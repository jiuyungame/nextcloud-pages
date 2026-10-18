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
 * finds, and **never deletes anything**: a row whose file disappeared is simply no longer listed, and
 * removing published content stays an explicit publisher action (see the plan's §9).
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
     * The reading list: every page of the site, filterable by tag, by visibility state and by "approved
     * for the site only". Reading needs no visibility filter of its own: a signed-in user sees every
     * entry that exists and belongs to the site, and each row says which of the three states it is in.
     *
     * @return array{articles: list<array<string,mixed>>, truncated: bool}
     */
    public function listed(string $viewerUid, string $tag = '', string $sort = 'new', string $state = '', bool $externalOnly = false): array
    {
        return $this->select($viewerUid, 'published', $tag, $sort, $state, $externalOnly);
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
     * next configuration push asks who the authors are. An existing row keeps its visibility state:
     * editing a page is not a visibility change, and there is no content review that an edit could take
     * back - a page that is `external` stays approved for the site while it is being corrected.
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
     *  - **is the entry approved for the site?** ({@see ReviewStore::onSite()}): a page that was taken
     *    down (`internal`) is not live any more, even though the site serves the old commit until the
     *    next publish; a pending take-offline request is still approved, so it stays live.
     *  - **did a commit carry it?** The manifest's published list answers that for an author's article.
     *    The site's own posts are not tracked there: an editor publishes them with the site, and the
     *    commit plus Jekyll's `published:` flag is the answer.
     *
     * The rule is static and public because the tree, the reading list and the publish step ask the
     * same question, and two answers to "is it live" would drift apart.
     *
     * @param array<string,mixed> $item owner/state/intent/path, as the scan or a cache row describes it
     * @param array<string,mixed> $manifest the visibility manifest, as `ReviewStore::read()` returned it
     */
    public static function isLive(array $item, array $manifest): bool
    {
        if (!ReviewStore::onSite($manifest, (string)($item['path'] ?? ''))) {
            return false;
        }
        if (($item['owner'] ?? '') === '') {
            // the site's own posts: the commit plus Jekyll's `published:` flag is the switch, and the
            // scan keeps the state in step with the file
            return ($item['state'] ?? '') === Article::STATE_EXTERNAL;
        }

        return ReviewStore::isPublished($manifest, (string)($item['path'] ?? ''));
    }

    /**
     * @return array{articles: list<array<string,mixed>>, truncated: bool}
     */
    private function select(string $viewerUid, string $scope, string $tag, string $sort, string $state = '', bool $externalOnly = false): array
    {
        $items = [];
        foreach ($this->collect($viewerUid) as $item) {
            if ($scope === 'mine') {
                if ($item['owner'] !== $viewerUid) {
                    continue;
                }
            } elseif (!($item['renderable'] ?? true)) {
                // an image or another non-renderable entry has no article page: it is listed in the
                // author's own tree only, not in the reading list
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

        // the site's own permalink pattern, read once: author area paths are relative to their own
        // folder, so the pattern is the same for every source of this site
        $pattern = $this->files->siteExists($owner, $ncPath)
            ? $this->permalinkPattern($owner, $ncPath)
            : FrontMatter::DEFAULT_PERMALINK;

        // 1. the skeleton: `_posts/…` of the site folder, unowned
        $scanned = [];
        if ($this->files->siteExists($owner, $ncPath)) {
            try {
                $tree = $this->files->tree($owner, $ncPath);
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
                $scanned['/' . ltrim($rel, '/')] = ['reader' => $owner, 'owner' => '', 'root' => $ncPath, 'rel' => $rel, 'node' => $node];
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
        if ($scanned === []) {
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
            $mtime = (int)($source['node']['mtime'] ?? 0);
            $renderable = $authorUid === '' ? true : $this->authorAreas()->isRenderable((string)$source['rel']);
            $parsed = ['data' => [], 'body' => ''];
            if ($renderable) {
                try {
                    // the file is the truth for title/date/tags/URL, so it is read every time; only the
                    // stored cache is written when the row is new or the file changed
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
            // `external`); an author's article follows the manifest, where a path it does not mention
            // is `internal` (whether the site *has* it is the published list, not the file)
            $state = $followFile
                ? (FrontMatter::isPublished($data) ? Article::STATE_EXTERNAL : Article::STATE_INTERNAL)
                : ReviewStore::stateOf($manifest, $path);
            $entry = ReviewStore::entryOf($manifest, $path);

            if ($row === null) {
                $row = $this->articles->upsertByPath($path, $authorUid, $title, implode(' ', $tags));
            } elseif ($mtime > (int)$row->getUpdatedAt() || (string)$row->getOwnerUid() !== $authorUid) {
                $row = $this->articles->upsertByPath($path, $authorUid, $title, implode(' ', $tags));
            }
            if ((string)$row->getState() !== $state) {
                // The manifest is the truth about a state, so a row that says something else is a stale
                // cache - a hand-edited or restored manifest, or a row written before the manifest
                // existed. Repairing it here (and not only on the next write) is what keeps the queue
                // and the counts, which are plain queries, in step with what the pages show. The entry's
                // own fields travel along, so a repair never clears a review note.
                $row = $this->articles->saveState(
                    (int)$row->getId(),
                    $state,
                    (string)($entry['note'] ?? ''),
                    (int)($entry['submittedAt'] ?? 0),
                    (int)($entry['publishedAt'] ?? 0),
                    (string)($entry['revision'] ?? ''),
                );
            }
            $rows[$path] = $row;
            // the pending request, as the author's own list and the request panel have to show it: the
            // intent is the direction the author asked for, and it is empty unless `requested`
            $intent = $followFile ? '' : ReviewStore::intentOf($manifest, $path);

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
                'submittedAt' => (int)($entry['submittedAt'] ?? 0),
                'publishedAt' => (int)($entry['publishedAt'] ?? 0),
                'revision' => (string)($entry['revision'] ?? ''),
            ];
            // decided here, where the manifest is already in hand: the reading list, the tag counts and
            // the single-article endpoint all ask the same question
            $item['live'] = self::isLive($item, $manifest);
            $articles[] = $item;
            $bodies[$path] = $renderable ? FrontMatter::summary($parsed['body']) : '';
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
     * The users whose author areas are scanned, resolved the same way the push resolves them: the
     * members of the authoring groups, or every administrator when no group is granted (which is the
     * same "everyone" the authoring capability uses). A user whose folder does not exist contributes
     * nothing, so an install that never configured this costs one folder lookup per admin.
     *
     * @return list<string>
     */
    private function authorUids(string $viewerUid = ''): array
    {
        // One authority for "who is an author": the same list the configuration push uses, so the
        // index and the bridge can never disagree about whose folder belongs to the site. The viewer
        // is always added: an author has to see their own new article immediately, even before the
        // first push has told the bridge - and the database - that they are an author.
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
