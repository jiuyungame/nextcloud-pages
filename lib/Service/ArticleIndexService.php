<?php

declare(strict_types=1);

namespace OCA\Pages\Service;

use OCA\Pages\Db\Article;
use OCA\Pages\Db\ArticleMapper;
use OCA\Pages\Db\CommentMapper;
use OCA\Pages\Db\VoteMapper;
use OCP\IUserManager;

/**
 * The article index: which posts exist, what they are called, which tags they carry, who wrote them,
 * and how the community rates and discusses them.
 *
 * The files stay the source of truth — they are what gets built and published — and the review manifest
 * ({@see ReviewStore}) is the truth about the workflow: which state an author's article is in and
 * whether the site has it. The database holds only the community state (votes, comments) plus a cache
 * of the title, the tags and the review trail, so a scan reads the source tree, registers what it
 * finds, and **never deletes anything**: a row whose file disappeared is no longer listed from the
 * tree, and removing published content stays an explicit publisher action (see the plan's §9). The one
 * exception is an entry with something pending: its source file can already be gone (the delete paths
 * leave a take-off request behind) and it still has to be listed, or the reviewer could never answer
 * the request and the page would stay on the site forever ({@see self::collect()}).
 */
final class ArticleIndexService
{
    /** Hard stop so one page view cannot walk an unbounded tree. */
    private const MAX_ARTICLES = 500;

    /** The extensions the site's own Jekyll posts have; an author's file may have any extension. */
    private const POST_EXTENSIONS = ['md', 'markdown', 'html'];

    /** The site's own posts folder (`_posts/**`); an author area is `Pages/` and needs no such a folder. */
    private const POST_DIR = '_posts';

    public function __construct(
        private SiteRegistry $registry,
        private FileService $files,
        private ArticleMapper $articles,
        private ReviewStore $review,
        private VoteMapper $votes,
        private CommentMapper $comments,
        private AuthorSourcesService $authorSources,
        /**
         * Only used to name an author in the reading list. A caller that does not hand one in (a unit
         * test, or an older call site) gets the uid as the name, which is the honest fallback.
         */
        private ?IUserManager $userManager = null,
        /**
         * Maps an author's files to their site targets and answers "is this renderable". A caller
         * that does not hand one in gets the container's: the mapping is the same everywhere.
         */
        private ?AuthorAreaService $areas = null,
        /**
         * Computes the built address of one file (an author's page address, or the site's own permalink
         * pattern). Handed in the same way as `$areas`, for the same reason.
         */
        private ?PageUrlService $pageUrls = null,
    ) {
    }

    /** The author-space mapping, resolved once. */
    private function authorAreas(): AuthorAreaService
    {
        return $this->areas ??= \OCP\Server::get(AuthorAreaService::class);
    }

    /** The page-address mapping, resolved once. */
    private function pageUrls(): PageUrlService
    {
        return $this->pageUrls ??= \OCP\Server::get(PageUrlService::class);
    }

    /**
     * The reading list: every page of the site, filterable by tag, by visibility state and by "the
     * site has a version of it". Reading needs no visibility filter of its own: a signed-in user sees
     * every entry that exists and belongs to the site, and each row says which of the three states it
     * is in.
     *
     * `$awaiting` is the request panel's narrower question: only entries with a candidate waiting in
     * `pending/` ({@see ReviewStore::isPending()}). It is one filter and not a fourth state - an online
     * page whose new content waits is still on the site, with its old version. An entry whose author is
     * still editing is deliberately **not** in it: the author decides whether the new content should go
     * out, and only their online request freezes it.
     *
     * @return array{articles: list<array<string,mixed>>, truncated: bool}
     */
    public function listed(
        string $viewerUid,
        string $tag = '',
        string $sort = 'new',
        string $state = '',
        bool $externalOnly = false,
        bool $awaiting = false,
    ): array {
        return $this->select($viewerUid, 'published', $tag, $sort, $state, $externalOnly, $awaiting);
    }

    /**
     * The author's own list, whatever state the articles are in.
     *
     * @return array{articles: list<array<string,mixed>>, truncated: bool}
     */
    public function mine(string $uid, string $tag = '', string $sort = 'new'): array
    {
        return $this->select($uid, 'mine', $tag, $sort);
    }

    /**
     * One article with its community data, or null when it does not exist. No visibility filtering:
     * being signed in and naming an entry of the site is enough, and the row carries the state.
     *
     * The row comes from {@see self::collect()} with the caller as the viewer: the workflow is
     * reported through the folder-derived fields (`published`, `candidate`, `downRequest`), which are a
     * fact of the review workflow and are handed to anyone who may see the article - the request panel
     * reads them for other people's pages.
     *
     * @return array<string,mixed>|null
     */
    public function single(string $viewerUid, string $path, bool $moderate = false): ?array
    {
        $wanted = '/' . ltrim($path, '/');
        foreach ($this->collect($viewerUid) as $item) {
            if ($item['path'] !== $wanted) {
                continue;
            }
            // an image or another non-renderable entry has no article page; a caller that may moderate
            // still has to see it, because the request decisions are taken about it
            if (!$moderate && !($item['renderable'] ?? true)) {
                return null;
            }

            return $item;
        }

        return null;
    }

    /**
     * How many articles carry each tag, most used first. Every entry counts: the reading list shows
     * them all, so the tag filter must not silently drop the tags of internal pages.
     *
     * @return list<array{tag: string, count: int}>
     */
    public function tagCounts(string $viewerUid): array
    {
        $counts = [];
        foreach ($this->collect($viewerUid) as $item) {
            foreach ($item['tags'] as $tag) {
                $counts[$tag] = ($counts[$tag] ?? 0) + 1;
            }
        }
        uksort($counts, static fn (string $a, string $b): int => strcasecmp($a, $b));
        $out = [];
        foreach ($counts as $tag => $count) {
            $out[] = ['tag' => $tag, 'count' => $count];
        }
        usort($out, static fn (array $a, array $b): int => [$b['count'], strtolower($a['tag'])] <=> [$a['count'], strtolower($b['tag'])]);

        return $out;
    }

    /**
     * Register (or refresh) a single article without walking the whole tree.
     *
     * The author's own save path calls this, so a brand new author is already in the database when the
     * next scan asks who the authors are. An existing row keeps its visibility state: editing a page
     * is not a visibility change, and there is no content review that an edit could take back - a page
     * that is `external` stays approved for the site while it is being corrected.
     */
    public function register(string $uid, string $area, string $rel, string $target): void
    {
        $renderable = $this->authorAreas()->isRenderable($rel);
        if ($renderable) {
            try {
                $raw = (string)($this->files->read($uid, $area, $rel)['content'] ?? '');
            } catch (\Throwable) {
                // gone between the write and this call, binary, or too large for the editor: not an article
                return;
            }
            $data = FrontMatter::parse($raw)['data'];
            $title = FrontMatter::title($data, $rel);
            $tags = implode(' ', FrontMatter::tags($data));
        } else {
            // an image or another file: an entry the app tracks and reviews, but not a document whose
            // front matter could be read - the name is all it has
            $title = basename($rel);
            $tags = '';
        }
        $path = '/' . ltrim($target, '/');
        // an article the manifest does not mention is internal: the row is inserted with that state,
        // and nothing else has to be written for a page that was never requested
        $this->articles->upsertByPath($path, $uid, $title, $tags);
    }

    /**
     * Whether the site is serving an article right now.
     *
     * Two independent facts, and this is where they meet:
     *  - **is the article approved for the site?** `online/<target>` is the answer
     *    ({@see ReviewStore::isLive()}): a page that was taken down is not live any more, even though
     *    the built site keeps the old output until the next build; a pending take-offline request is
     *    still served, so it stays live.
     *  - **the site's own posts** are not tracked in `online/`: an editor publishes them with the site,
     *    and the build plus Jekyll's `published:` flag is the answer.
     *
     * @param array<string,mixed> $item owner/state/path, as the scan or a cache row describes it
     */
    public function isLive(array $item): bool
    {
        if (($item['owner'] ?? '') === '') {
            // the site's own posts: the build plus Jekyll's `published:` flag is the switch
            return ($item['state'] ?? '') === Article::STATE_EXTERNAL;
        }

        return $this->review->isLive((string)($item['path'] ?? ''));
    }

    /**
     * @return array{articles: list<array<string,mixed>>, truncated: bool}
     */
    private function select(string $viewerUid, string $scope, string $tag, string $sort, string $state = '', bool $externalOnly = false, bool $awaiting = false): array
    {
        $items = [];
        foreach ($this->collect($viewerUid) as $item) {
            if ($scope === 'mine') {
                if ($item['owner'] !== $viewerUid) {
                    continue;
                }
            } elseif (!($item['renderable'] ?? true)
                && ($item['candidate'] ?? false) !== true
                && ($item['downRequest'] ?? false) !== true) {
                // an image or another non-renderable entry has no article page: it is listed in the
                // author's own tree only, not in the reading list. An entry with something pending is
                // the exception: its source file may already be gone, and the request it carries must
                // stay visible to the reviewer who has to answer it - in the request panel and in any
                // list the requested state is asked for.
                continue;
            }
            if ($tag !== '' && !$this->hasTag($item['tags'], $tag)) {
                continue;
            }
            // the visibility state, for a reader who wants to see one kind of entry: applied here and
            // not in the browser, so a truncated list cannot pretend nothing matched
            if ($state !== '' && (string)$item['state'] !== $state) {
                continue;
            }
            // "approved for the site only": the filter the external list asks for
            if ($externalOnly && (string)$item['state'] !== Article::STATE_EXTERNAL) {
                continue;
            }
            // "a frozen candidate is waiting for a decision": the request panel's own list. It is the
            // author's *request* - the bytes were frozen when they asked - and not merely an edit, so
            // an entry whose author has not asked yet stays out of the queue.
            if ($awaiting && ($item['candidate'] ?? false) !== true) {
                continue;
            }
            // `live` is decided per item by the scan, from the manifest it read once: an approved entry
            // that no commit has carried yet is external but not on the site
            $items[] = $item;
        }

        $this->sort($items, $sort);
        $truncated = count($items) > self::MAX_ARTICLES;
        if ($truncated) {
            $items = array_slice($items, 0, self::MAX_ARTICLES);
        }

        return ['articles' => $items, 'truncated' => $truncated];
    }

    /**
     * @param list<string> $tags
     */
    private function hasTag(array $tags, string $tag): bool
    {
        foreach ($tags as $candidate) {
            if (strcasecmp($candidate, $tag) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string,mixed>> $items
     */
    private function sort(array &$items, string $sort): void
    {
        usort($items, match ($sort) {
            'score' => static fn (array $a, array $b): int => [$b['score'], $b['date']] <=> [$a['score'], $a['date']],
            'comments' => static fn (array $a, array $b): int => [$b['comments'], $b['date']] <=> [$a['comments'], $a['date']],
            default => static fn (array $a, array $b): int => [$b['date'], $b['title']] <=> [$a['date'], $a['title']],
        });
    }

    /**
     * Scan every source tree and return every article with its community data.
     *
     * Two kinds of source, one list:
     *  - the **site skeleton**: `_posts/…` inside the site folder, owned by nobody (`owner_uid = ''`),
     *    whose visibility follows the file's own `published:` flag (that is Jekyll's switch, not a
     *    review decision);
     *  - every **author area**: the whole `Pages/` tree inside a participating user's own files,
     *    mirrored into the site under `<author_root>/<uid>/…` and registered under that uid. The
     *    manifest is the authority for those, so their state comes from it and the file's `published:`
     *    flag is not consulted. An author's files are pages of the site template - images and other
     *    resources included - never posts.
     *
     * What identifies an article is the site-relative path it will have once published, so the
     * skeleton's `_posts/2026-01-01-x.md` and an author's `<author_root>/alice/2026-01-01-x.md` are two
     * articles of the same list, addressed the same way by every endpoint.
     *
     * The manifest is read once per scan and then asked per article: a page view must not read the same
     * file once per post.
     *
     * A path no tree yielded is added a second time from the manifest when it carries a pending
     * request: the file is gone, but the reviewer still has to decide about the entry (see step 3 at
     * the end of {@see self::collect()}). A path whose file is gone and has nothing pending stays out -
     * it is a removal the next publish carries out, not an article.
     *
     * @return list<array<string,mixed>>
     */
    private function collect(string $viewerUid): array
    {
        $site = $this->registry->getSite();
        if ($site === null) {
            return [];
        }
        $owner = trim((string)($site['nc_user'] ?? ''));
        $ncPath = trim((string)($site['nc_path'] ?? ''));
        if ($owner === '' || $ncPath === '') {
            return [];
        }
        $manifest = $this->review->read();
        // A manifest that could not be read is not an empty one: every author entry is then unknown
        // rather than `internal`, so the states the cache already holds are the only answer there is
        // and this scan must not "repair" them from the empty document it got instead
        // ({@see ReviewStore::isCorrupt()}).
        $corrupt = $this->review->isCorrupt();
        // read once for the whole scan: whether the last publish's push was attempted and failed. An
        // entry carried by that commit is live but not on the remote, which is exactly what the row's
        // `pushFailed` flag reports.
        $pushFailed = $this->registry->getPublishPushFailure() !== null;

        // the site's own permalink pattern, read once: author area paths are relative to their own
        // folder, so the pattern is the same for every source of this site. The skeleton lives in
        // `source/` since the three-directory layout, so the pattern and the posts are read there.
        $sourcePath = rtrim($ncPath, '/') . '/' . SiteTrees::SOURCE;
        $pattern = $this->files->siteExists($owner, $sourcePath)
            ? $this->permalinkPattern($owner, $sourcePath)
            : FrontMatter::DEFAULT_PERMALINK;

        // 1. the skeleton: `_posts/…` of the site's `source/` folder, unowned
        $scanned = [];
        if ($this->files->siteExists($owner, $sourcePath)) {
            try {
                $tree = $this->files->tree($owner, $sourcePath);
            } catch (\Throwable) {
                $tree = ['nodes' => []];
            }
            foreach (($tree['nodes'] ?? []) as $node) {
                if (($node['type'] ?? '') !== 'file') {
                    continue;
                }
                $rel = (string)($node['path'] ?? '');
                if (!$this->isPost($rel)) {
                    continue;
                }
                $scanned['/' . ltrim($rel, '/')] = ['reader' => $owner, 'owner' => '', 'root' => $sourcePath, 'rel' => $rel, 'node' => $node];
                if (count($scanned) >= self::MAX_ARTICLES * 2) {
                    break;
                }
            }
        }

        // 2. the author areas: the whole `Pages/` tree of each participating user, mirrored into the
        //    site under `<author_root>/<uid>/`; images are entries exactly like pages
        $area = $this->authorAreas()->area();
        foreach ($this->authorUids($viewerUid) as $uid) {
            if (count($scanned) >= self::MAX_ARTICLES * 2) {
                break;
            }
            try {
                if (!$this->files->siteExists($uid, $area)) {
                    continue;
                }
                $tree = $this->files->tree($uid, $area);
            } catch (\Throwable) {
                continue;
            }
            foreach (($tree['nodes'] ?? []) as $node) {
                if (($node['type'] ?? '') !== 'file') {
                    continue;
                }
                $rel = (string)($node['path'] ?? '');
                if ($rel === '' || !$this->authorAreas()->isMirrored($rel)) {
                    continue;
                }
                $target = '/' . $this->authorAreas()->targetFor($uid, $rel);
                $scanned[$target] = ['reader' => $uid, 'owner' => $uid, 'root' => $area, 'rel' => $rel, 'node' => $node];
                if (count($scanned) >= self::MAX_ARTICLES * 2) {
                    break;
                }
            }
        }
        /*
         * The requests the walk cannot find. The scan describes files that exist, while a deleted
         * file's take-off request (`sourceDeleted: true`, filed by both delete paths) is a fact of the
         * manifest alone, and a candidate whose source was deleted is a fact of `pending/` alone: the
         * entry has to stay in the lists - the request panel and the counts - until a reviewer answers
         * it, or nobody ever can and the page stays on the site forever.
         */
        $pending = [];
        foreach (array_keys($manifest['entries']) as $key) {
            $key = (string)$key;
            if (ReviewStore::downRequestOf($manifest, $key) !== null) {
                $pending['/' . ltrim($key, '/')] = $key;
            }
        }
        foreach ($this->review->pendingTargets() as $key) {
            $pending['/' . ltrim($key, '/')] = $key;
        }
        if ($scanned === [] && $pending === []) {
            return [];
        }

        $rows = $this->rowsByPath();
        $articles = [];
        $bodies = [];
        $names = [];
        foreach ($scanned as $path => $source) {
            $row = $rows[$path] ?? null;
            // who owns the article ('' for the skeleton) and whose files to read it from differ for
            // the skeleton: its posts belong to the site, but they live in the storage user's folder
            $authorUid = (string)$source['owner'];
            $reader = (string)$source['reader'];
            // an author post is the manifest's to decide about, the skeleton's own history keeps
            // following Jekyll's `published` flag
            $followFile = $authorUid === '';
            $renderable = $authorUid === '' ? true : $this->authorAreas()->isRenderable((string)$source['rel']);
            $parsed = ['data' => [], 'body' => ''];
            if ($renderable) {
                try {
                    // the file is the truth for title/date/tags/URL, so it is read every time; the
                    // stored cache is written only when the row is new or its parse differs
                    $raw = (string)($this->files->read($reader, $source['root'], $source['rel'])['content'] ?? '');
                } catch (\Throwable) {
                    // binary, too large, or gone between the walk and the read: not an article
                    continue;
                }
                $parsed = FrontMatter::parse($raw);
                $data = $parsed['data'];
                $title = FrontMatter::title($data, $source['rel']);
                $tags = FrontMatter::tags($data);
            } else {
                // an image (or another non-renderable entry): no front matter to read, no page to
                // render - it is still a row, because the author reviews it like any other file
                $data = [];
                $title = basename((string)$source['rel']);
                $tags = [];
            }
            // the skeleton follows its front matter (`published:` true means the site builds it, i.e.
            // `external`); an author's article follows the folders, where `online/` means the site
            // serves it and a take-down request means something waits
            $state = $followFile
                ? (FrontMatter::isPublished($data) ? Article::STATE_EXTERNAL : Article::STATE_INTERNAL)
                : $this->review->stateIn($manifest, $path);
            if ($corrupt && !$followFile && $row !== null) {
                // nothing to derive a state from: keep the one the cached row already carries, so a
                // page that is on the site does not read as a draft just because the manifest is
                // unreadable
                $state = (string)$row->getState();
            }
            $entry = ReviewStore::entryOf($manifest, $path);
            // the entry's own facts, read once: the direction of a pending request, the candidate in
            // pending/ (and since when), the take-offline request and the version the site serves. A
            // site-owned post has no entry at all - its state and `live` follow the file
            $intent = $followFile ? '' : $this->review->intentIn($manifest, $path);
            $candidateAt = $followFile ? 0 : $this->review->pendingStamp($path);
            $isPending = $followFile ? false : $this->review->isPending($path);
            $downRequest = $followFile ? null : ReviewStore::downRequestOf($manifest, $path);
            $publishedAt = $followFile ? 0 : $this->review->liveStamp($path);
            $isLive = $followFile ? $state === Article::STATE_EXTERNAL : $this->review->isLive($path);

            // The file is the truth, so the title and the tags are parsed on every pass; the cache row
            // is written only when what it holds differs from what the file says. Comparing the fields
            // themselves is exact - both columns are a projection of the parse - and it is the only
            // test that cannot ask for a write when nothing changed: the file-mtime-against-
            // `updated_at` heuristic it replaces was a proxy, and every write it did trigger rewrote
            // the same two values (and moved the very timestamp the next pass compared against).
            $tagsLine = implode(' ', $tags);
            if ($row === null
                || (string)$row->getOwnerUid() !== $authorUid
                || (string)$row->getTitle() !== $title
                || (string)$row->getTags() !== $tagsLine) {
                $row = $this->articles->upsertByPath($path, $authorUid, $title, $tagsLine);
            }
            if (!$corrupt && (string)$row->getState() !== $state) {
                // The manifest is the truth about a state, so a row that says something else is a stale
                // cache - a hand-edited or restored manifest, or a row written before the manifest
                // existed. Repairing it here (and not only on the next write) is what keeps the queue
                // and the counts, which are plain queries, in step with what the pages show. The entry's
                // own fields travel along, so a repair never clears a review note.
                //
                // It never runs over a corrupt manifest: `read()` answers the empty document there, and
                // writing that back would erase every state the table still holds - the one copy left
                // until the file is repaired.
                $row = $this->articles->saveState(
                    (int)$row->getId(),
                    $state,
                    (string)($entry['note'] ?? ''),
                    // the queue reads the moment the pending request was filed (either direction),
                    // and the panel the moment the served version was last written
                    $candidateAt > 0 ? $candidateAt : ($downRequest['at'] ?? 0),
                    $publishedAt,
                    '',
                );
            }
            $rows[$path] = $row;

            $item = [
                'id' => (int)$row->getId(),
                'path' => $path,
                'title' => $title,
                'owner' => (string)$row->getOwnerUid(),
                'author' => $this->displayName((string)$row->getOwnerUid(), $names),
                'tags' => $tags,
                'state' => $state,
                // the direction of a pending request (`online` / `offline`), '' while none is pending
                'intent' => $intent,
                'renderable' => $renderable,
                'date' => $renderable ? FrontMatter::date($data, $source['rel']) : 0,
                // the address is computed per kind of source: an author's page by the site template's
                // own mirror rule, a skeleton post by the site's permalink pattern
                'url' => $this->urlOf($renderable, $authorUid, $owner, $ncPath, (string)$source['rel'], $data, $pattern),
                // the decision trail travels with the article: an author has to see why a request came
                // back. It comes from the manifest, which is the decision's own record.
                'reviewNote' => (string)($entry['note'] ?? ''),
                'decidedBy' => (string)($entry['decidedBy'] ?? ''),
                // the moment the pending request was filed (either direction), 0 while none is
                'submittedAt' => $candidateAt > 0 ? $candidateAt : ($downRequest['at'] ?? 0),
                // the moment the site's version was last written, 0 while it has none
                'publishedAt' => $publishedAt,
                // the folder-derived facts: whether the site serves a version, whether one waits in
                // pending/ (and since when), and whether a take-offline request is waiting. A
                // site-owned post has no entry at all - its state and `live` follow the file
                'published' => $isLive,
                'candidate' => $isPending,
                'candidateAt' => $candidateAt,
                'downRequest' => $downRequest !== null,
                // whether the author's file is already gone. This row came from the scan, which just
                // found the file, so the answer is no - whatever the request recorded before the file
                // came back is history. The decision reads the filesystem at decision time
                // ({@see \OCA\Pages\Service\ArticleReviewService::decide()}), and the display has to
                // report the same current fact; only the pending-only rows below have no file to read.
                'sourceDeleted' => false,
            ];
            // decided here, where the folders are already in hand: the reading list, the tag counts and
            // the single-article endpoint all ask the same question
            $item['live'] = $this->isLive($item);
            // a live entry is shown as Online; when the last publish's push was attempted and failed,
            // the same entry is marked `pushFailed` - the commit carried it, the remote did not get it
            $item['pushFailed'] = $item['live'] && $pushFailed;
            $articles[] = $item;
            $bodies[$path] = $renderable ? FrontMatter::summary($parsed['body']) : '';
        }

        // 3. an entry whose source file is gone but whose request still waits for a decision. It has
        //    no file to read, so everything an article normally takes from one - title, date, URL,
        //    tags, whether it can be rendered - is empty or derived from the path; what the reviewer
        //    decides about (the request, the served version, the trail) comes from the manifest, like
        //    every other row. Paths the scan did collect are skipped: the file is the better answer.
        $collected = [];
        foreach ($articles as $article) {
            $collected[(string)$article['path']] = true;
        }
        foreach ($pending as $path => $key) {
            if (isset($collected[$path])) {
                continue;
            }
            $entry = ReviewStore::entryOf($manifest, $key);
            $owner = trim((string)($entry['owner'] ?? ''));
            $candidateAt = $this->review->pendingStamp($key);
            $isPending = $this->review->isPending($key);
            $downRequest = ReviewStore::downRequestOf($manifest, $key);
            $publishedAt = $this->review->liveStamp($key);
            $isLive = $this->review->isLive($key);
            // the cache row may still be there (the file was indexed while it existed): its id and
            // the decision trail keep the community data and the review note attached to the row
            $row = $rows[$path] ?? null;
            $item = [
                'id' => $row !== null ? (int)$row->getId() : 0,
                'path' => $path,
                'title' => basename($path),
                'owner' => $owner,
                'author' => $this->displayName($owner, $names),
                'tags' => [],
                'state' => $this->review->stateIn($manifest, $key),
                'intent' => $this->review->intentIn($manifest, $key),
                'renderable' => false,
                'date' => 0,
                'url' => '',
                'reviewNote' => (string)($entry['note'] ?? ''),
                'decidedBy' => (string)($entry['decidedBy'] ?? ''),
                'submittedAt' => $candidateAt > 0 ? $candidateAt : ($downRequest['at'] ?? 0),
                'publishedAt' => $publishedAt,
                'published' => $isLive,
                'candidate' => $isPending,
                'candidateAt' => $candidateAt,
                'downRequest' => $downRequest !== null,
                // no source file was scanned for this row: the request's recorded flag is the only
                // fact there is, and the decision would read the same absent file
                'sourceDeleted' => ($downRequest['sourceDeleted'] ?? false) === true,
            ];
            $item['live'] = $this->isLive($item);
            $item['pushFailed'] = $item['live'] && $pushFailed;
            $articles[] = $item;
        }

        return $this->withCommunity($articles, $bodies, $viewerUid);
    }

    /**
     * The address one article has in the built site.
     *
     * An author's file is a page of the site template, so the template's author mirror decides where
     * it lands (see {@see PageUrlService}); the site's own `_posts/**` keep the permalink pattern,
     * which is what Jekyll applies to a post.
     *
     * @param array<string,mixed> $data the parsed front matter
     */
    private function urlOf(
        bool $renderable,
        string $authorUid,
        string $siteUser,
        string $ncPath,
        string $rel,
        array $data,
        string $pattern,
    ): string {
        if (!$renderable) {
            return '';
        }
        if ($authorUid === '') {
            return FrontMatter::url($pattern, $data, $rel);
        }

        return $this->pageUrls()->forAuthorPage($siteUser, $ncPath, $authorUid, $rel, $data);
    }

    /**
     * The users whose author areas are scanned: the members of the authoring groups and everyone who
     * already owns an article (exactly the list AuthorSourcesService::authors() builds), plus the
     * viewer. A user whose folder does not exist contributes nothing, so an install that never
     * configured this costs one folder lookup per author.
     *
     * @return list<string>
     */
    private function authorUids(string $viewerUid = ''): array
    {
        // One authority for "who is an author": the same list the repair scan and the overwrite use, so
        // the index and the bridge can never disagree about whose folder belongs to the site. The viewer
        // is always added: an author has to see their own new article immediately, even before the
        // first scan has told the database that they are an author.
        $uids = [];
        try {
            foreach ($this->authorSources->authors() as $uid) {
                if ($uid !== '') {
                    $uids[$uid] = true;
                }
            }
        } catch (\Throwable) {
            // a group or user backend that cannot answer leaves the list as it is: the skeleton's own
            // posts are still indexed, which is what a broken backend must not take down
        }
        $viewerUid = trim($viewerUid);
        if ($viewerUid !== '') {
            $uids[$viewerUid] = true;
        }
        $out = array_keys($uids);
        sort($out);

        return $out;
    }

    /**
     * The display name behind an owner, or '' for the skeleton and the uid when nobody can answer.
     * Memoised per scan: one user manager lookup per author, not one per post.
     *
     * @param array<string,string> $cache
     */
    private function displayName(string $uid, array &$cache = []): string
    {
        if ($uid === '' || $this->userManager === null) {
            return '';
        }
        if (array_key_exists($uid, $cache)) {
            return $cache[$uid];
        }
        try {
            $user = $this->userManager->get($uid);
            $name = $user === null ? '' : trim($user->getDisplayName());
        } catch (\Throwable) {
            $name = '';
        }

        return $cache[$uid] = $name !== '' ? $name : $uid;
    }

    /**
     * Add vote and comment data to the scanned articles, in three batched queries.
     *
     * @param list<array<string,mixed>> $articles
     * @param array<string,string> $summaries
     * @return list<array<string,mixed>>
     */
    private function withCommunity(array $articles, array $summaries, string $viewerUid): array
    {
        $ids = [];
        foreach ($articles as $article) {
            $ids[] = (int)$article['id'];
        }
        $votes = $ids === [] ? [] : $this->votes->aggregateForArticles($ids);
        $counts = $ids === [] ? [] : $this->comments->countForArticles($ids);
        $mine = $ids === [] ? [] : $this->votes->findForArticlesAndVoter($ids, $viewerUid);

        foreach ($articles as $index => $article) {
            $id = (int)$article['id'];
            $up = (int)($votes[$id]['up'] ?? 0);
            $down = (int)($votes[$id]['down'] ?? 0);
            $articles[$index] += [
                'summary' => $summaries[$article['path']] ?? '',
                'up' => $up,
                'down' => $down,
                'score' => $up - $down,
                'mine' => (int)($mine[$id] ?? 0),
                'comments' => (int)($counts[$id] ?? 0),
            ];
        }

        return $articles;
    }

    /**
     * The stored rows, keyed by path. The index is small (one row per post), so a full read is
     * cheaper and simpler than a query per file.
     *
     * @return array<string,Article>
     */
    private function rowsByPath(): array
    {
        $rows = [];
        foreach ($this->articles->findAll() as $row) {
            $rows[(string)$row->getPath()] = $row;
        }

        return $rows;
    }

    /** The site's permalink pattern, so the front end can link straight at the built page. */
    private function permalinkPattern(string $uid, string $ncPath): string
    {
        try {
            $raw = (string)($this->files->read($uid, $ncPath, '_config.yml')['content'] ?? '');
        } catch (\Throwable) {
            return FrontMatter::DEFAULT_PERMALINK;
        }
        $pattern = trim((string)(FrontMatter::document($raw)['permalink'] ?? ''));

        return $pattern !== '' ? $pattern : FrontMatter::DEFAULT_PERMALINK;
    }

    /** A Jekyll post: a text file under `_posts/`, at any depth (Jekyll reads it recursively). */
    private function isPost(string $rel): bool
    {
        $rel = ltrim($rel, '/');
        if (!str_starts_with($rel, self::POST_DIR . '/')) {
            return false;
        }

        return $this->isPostFile($rel);
    }

    /**
     * A site post by extension, at any depth. Only the site's own `_posts/**` is scanned this way
     * (`isPost()` adds the prefix); an author's tree is scanned whole, because every file in it is an
     * entry - a page or a resource.
     */
    private function isPostFile(string $rel): bool
    {
        $rel = ltrim($rel, '/');
        if ($rel === '' || str_starts_with($rel, '.')) {
            return false;
        }
        $ext = strtolower((string)pathinfo($rel, PATHINFO_EXTENSION));

        return in_array($ext, self::POST_EXTENSIONS, true);
    }
}
