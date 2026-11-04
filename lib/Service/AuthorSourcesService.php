<?php

declare(strict_types=1);

namespace OCA\Pages\Service;

use OCA\Pages\Db\ArticleMapper;
use OCP\IUserManager;
use OCP\Server;

/**
 * The author sources: the files the bridge has to mirror from the authors' own folders, and what
 * state each author's space is in.
 *
 * The scan lives here because it is the one place where the three representations meet: the folder
 * (what is really there), the target namespace (what the published site serves) and the content
 * directories (`online/`, `pending/`). The steady-state push carries none of it - a save travels as a
 * file-event delta ({@see AuthorDeltaService}) - so this service feeds the one-shot overwrite
 * ({@see overwritePayload()}), Apply source's `authors` list ({@see authorAreas()}), the repair scan
 * and the admin status table instead.
 *
 * Each author block carries one list: `entries`, everything the area holds (mirrored into the preview
 * tree, so an internal page stays previewable). What is approved for the site is not a list here any
 * more - it is `online/`, which the bridge reads itself.
 */
final class AuthorSourcesService
{
    private IUserManager $userManager;

    public function __construct(
        private AuthorAreaService $areas,
        private ArticleMapper $articles,
        private SiteRegistry $registry,
        private ReviewStore $review,
        ?IUserManager $userManager = null,
    ) {
        $this->userManager = $userManager ?? Server::get(IUserManager::class);
    }

    /**
     * Every user whose folder counts as an author area: the members of the authoring groups, plus
     * everyone who already owns an article.
     *
     * An empty grant means "every signed-in user may author", so the population that matters is not
     * "who is an administrator" but "who actually wrote something" — that is the set the database
     * knows about, and it keeps a non-admin author's posts from being silently left out of the site.
     *
     * @return list<string>
     */
    public function authors(): array
    {
        $uids = [];
        foreach ($this->registry->getAuthorGroups() as $gid) {
            try {
                foreach ($this->userManager->getUsersInGroup($gid) as $user) {
                    $uid = trim((string)$user->getUID());
                    if ($uid !== '') {
                        $uids[$uid] = true;
                    }
                }
            } catch (\Throwable) {
                // a group backend that cannot answer must not take the whole push down: the other
                // groups still contribute, and the doctor reports the group that is missing
                continue;
            }
        }
        foreach ($this->articles->findAll() as $row) {
            $owner = trim((string)$row->getOwnerUid());
            if ($owner !== '' && $this->userManager->get($owner) !== null) {
                // a row can outlive its account: a deleted user is not an author any more, and their
                // folder cannot be read (asking for it would fail the whole push)
                $uids[$owner] = true;
            }
        }
        $out = array_keys($uids);
        sort($out);

        return $out;
    }

    /**
     * Target paths grouped by the author they belong to; a path that names nobody is dropped, because
     * no author block would carry it. The paths are normalised to the relative form the payload uses:
     * the caller knows an article as it is stored (`/<author_root>/<uid>/…`), and the bridge refuses
     * an absolute or otherwise unclean path.
     *
     * @param list<string> $targets
     * @return array<string,array<string,true>>
     */
    private function byAuthor(array $targets): array
    {
        $out = [];
        foreach ($targets as $target) {
            $target = ltrim(trim(str_replace('\\', '/', $target)), '/');
            $uid = $target === '' ? null : $this->areas->uidOfTarget($target);
            if ($uid !== null) {
                $out[$uid][$target] = true;
            }
        }

        return $out;
    }

    /**
     * Push the configuration. Author content no longer travels in this payload: the steady state is
     * the file-event delta, and the one-shot overwrite carries the complete author state. Passing the
     * author block here would resurrect the cache-driven `removed` list the delta design deleted.
     *
     * `$drop` is kept for the call sites that name a deletion, but there is no author block left to
     * apply it to and it is not sent.
     *
     * @param array<string,string>|null $secrets rotation payload, when the caller rotates
     * @return array{authors:int,result:array<string,mixed>,errors:array<string,string>}
     */
    public function pushWithAuthors(BridgeClient $bridge, ?array $secrets = null, array $drop = []): array
    {
        $result = $bridge->pushConfig($secrets === null ? [] : ['secrets' => $secrets]);

        return ['authors' => 0, 'result' => $result, 'errors' => []];
    }

    /**
     * The complete author state for the one-shot overwrite: every scanned target per author. The
     * bridge repairs the **preview** tree's author area from it (the commit tree's author area is
     * `online/`, which a boot must not touch), removes strays under each author prefix, and empties the
     * `purge` roots.
     *
     * The `on_site` list is gone: what is approved for the site is `online/`, and the bridge reads it
     * from there. Nothing in the payload claims "this is published" any more - a wrong claim would put
     * an unreviewed file live.
     *
     * @return array{authors:list<array{uid:string,area:string,entries:list<string>}>,purge:list<string>}
     */
    public function overwritePayload(): array
    {
        $scan = $this->scan();
        $root = $this->registry->getAuthorRoot();
        $byUid = [];
        foreach ($scan['entries'] as $entry) {
            $byUid[(string)$entry['uid']][] = (string)$entry['target'];
        }
        $authors = [];
        foreach ($byUid as $uid => $entries) {
            sort($entries);
            $authors[] = ['uid' => $uid, 'area' => $this->areas->area(), 'entries' => $entries];
        }
        $purge = [];
        foreach ($this->registry->getAuthorSources() as $targets) {
            foreach ($targets as $target) {
                $rootName = explode('/', ltrim((string)$target, '/'))[0] ?? '';
                if ($rootName !== '' && $rootName !== $root && !in_array($rootName, $purge, true)) {
                    $purge[] = $rootName;
                }
            }
        }

        return ['authors' => $authors, 'purge' => $purge];
    }

    /**
     * The author areas as a bridge `mirror` (Apply source) request needs them: one `{uid, area}` per
     * author whose folder is layered into the preview tree.
     *
     * The list has to cover **every** author: the bridge layers exactly the areas it is given, so a uid
     * that is missing contributes no drafts and the rebuilt preview would quietly lose pages.
     *
     * @return list<array{uid:string,area:string}>
     */
    public function authorAreas(): array
    {
        $out = [];
        foreach ($this->authors() as $uid) {
            $out[] = ['uid' => $uid, 'area' => $this->areas->area()];
        }

        return $out;
    }

    /**
     * Every mirrored file of every author area right now, flat - the same `entries` the one-shot
     * overwrite carries, without the removal bookkeeping that needed the previous push.
     *
     * `occ pages:repair` re-derives the article rows from exactly this list: a file that was written
     * outside the app (the Files app, a sync client, a restored backup) has no row, and re-saving every
     * page by hand is what this replaces. It is also what makes "how much is not on the bridge yet"
     * answerable against the legacy target map an older install left behind; no new map is written.
     *
     * A read error is returned, never swallowed: an area the app cannot read must be visible in the
     * repair output, otherwise a silent half-scan would look like a complete one.
     *
     * @return array{entries:list<array{uid:string,path:string,target:string,renderable:bool}>,errors:array<string,string>}
     */
    public function scan(): array
    {
        $entries = [];
        $errors = [];
        foreach ($this->authors() as $uid) {
            try {
                if (!$this->areas->areaExists($uid)) {
                    continue;
                }
                [$files] = $this->entries($uid, []);
            } catch (\Throwable $e) {
                $errors[$uid] = $e->getMessage();
                continue;
            }
            foreach ($files as $file) {
                $entries[] = ['uid' => $uid] + $file;
            }
        }

        return ['entries' => $entries, 'errors' => $errors];
    }

    /**
     * The status table of the admin panel: one row per author, answering "is the area there, how much
     * is in it, and how much of it the site does not have".
     *
     * `missing` is always empty now: the old answer compared the current scan against a remembered
     * push target list, and that cache is gone by design, so "what the bridge still holds but the
     * folder no longer has" cannot be computed app-side any more. The key stays in the shape for the
     * admin table, which reads `files`, `posts`, `missing` and `unpublished`.
     *
     * @return list<array{uid:string,displayName:string,area:string,posts:int,files:int,missing:list<string>,unpublished:int}>
     */
    public function status(): array
    {
        $unpublished = $this->countsByOwner();
        $rows = [];
        foreach ($this->authors() as $uid) {
            $counts = $this->counts($uid);
            $rows[] = [
                'uid' => $uid,
                'displayName' => $this->areas->displayName($uid),
                'area' => '/' . $this->areas->area(),
                'posts' => $counts['posts'],
                'files' => $counts['files'],
                // no remembered target list to diff against: drift is not an app-side concept any more
                'missing' => [],
                'unpublished' => $unpublished[$uid] ?? 0,
            ];
        }

        return $rows;
    }

    /**
     * What an author's area holds, as the status table and the doctor both need it: the renderable
     * files (what the app calls a post), every mirrored file, and the targets the manifest approves.
     * All of it reads the folder, not the database, because the folder is what gets published.
     *
     * @return array{posts:int,files:int,targets:list<string>,exists:bool,readable:bool}
     */
    public function counts(string $uid): array
    {
        if (!$this->areas->areaExists($uid)) {
            return ['posts' => 0, 'files' => 0, 'targets' => [], 'exists' => false, 'readable' => true];
        }
        try {
            $tree = $this->areas->tree($uid);
        } catch (\Throwable) {
            return ['posts' => 0, 'files' => 0, 'targets' => [], 'exists' => true, 'readable' => false];
        }
        $posts = 0;
        $files = 0;
        $targets = [];
        foreach ($tree['nodes'] as $node) {
            if (($node['type'] ?? '') !== 'file') {
                continue;
            }
            $rel = (string)($node['path'] ?? '');
            if ($rel === '' || !$this->areas->isMirrored($rel)) {
                continue;
            }
            $files++;
            if ($this->areas->isRenderable($rel)) {
                $posts++;
            }
            $target = $this->areas->targetFor($uid, $rel);
            if ($target !== '' && $this->mirrored($target)) {
                $targets[] = $target;
            }
        }
        sort($targets);

        return ['posts' => $posts, 'files' => $files, 'targets' => $targets, 'exists' => true, 'readable' => true];
    }

    /**
     * The directories of one author area that start with `_`. Such a folder is mirrored like every
     * other one, but Jekyll does not render pages inside it; the doctor warns about it, and this is
     * the one place that reads the tree for that answer.
     *
     * @return list<string>
     */
    public function underscoredDirectories(string $uid): array
    {
        $out = [];
        foreach ($this->areas->tree($uid)['nodes'] as $node) {
            if (($node['type'] ?? '') !== 'dir') {
                continue;
            }
            $rel = ltrim((string)($node['path'] ?? ''), '/');
            if ($rel === '') {
                continue;
            }
            foreach (explode('/', $rel) as $segment) {
                if (str_starts_with($segment, '_') && $segment !== '_') {
                    $out[] = $rel;
                    continue 2;
                }
            }
        }
        sort($out);

        return array_values(array_unique($out));
    }

    /**
     * One author's two entry lists, and the flat target lists that go with them.
     *
     * Every file of the area is an entry of the preview list - an image exactly like a page. The
     * preview tree mirrors that list, so an internal page is built and therefore previewable; the
     * commit tree is assembled from `source/` + `online/`, so this mapping never publishes anything.
     * Merging the two lists would make internal pages un-previewable.
     *
     * Only the preview half is consumed now: `scan()` passes an empty set, and the overwrite carries
     * every scanned target - what the site serves is `online/`, which the bridge reads itself. The
     * on-site halves stay because this is still the one mapping from an area path to a target, and a
     * caller that has the on-site subset should not have to repeat it.
     *
     * @param array<string,true> $onSite targets that are approved for the site
     * @return array{0:list<array{path:string,target:string,renderable:bool}>,1:list<string>,2:list<array{path:string,target:string,renderable:bool}>,3:list<string>}
     */
    private function entries(string $uid, array $onSite): array
    {
        $entries = [];
        $targets = [];
        $onSiteEntries = [];
        $onSiteTargets = [];
        foreach ($this->areas->tree($uid)['nodes'] as $node) {
            if (($node['type'] ?? '') !== 'file') {
                continue;
            }
            $rel = (string)($node['path'] ?? '');
            if ($rel === '' || !$this->areas->isMirrored($rel)) {
                continue;
            }
            $target = $this->areas->targetFor($uid, $rel);
            if ($target === '') {
                continue;
            }
            $entry = [
                'path' => $rel,
                'target' => $target,
                'renderable' => $this->areas->isRenderable($rel),
            ];
            $entries[] = $entry;
            $targets[] = $target;
            if (isset($onSite[$target])) {
                $onSiteEntries[] = $entry;
                $onSiteTargets[] = $target;
            }
        }
        usort($entries, static fn (array $a, array $b): int => strcmp($a['target'], $b['target']));
        usort($onSiteEntries, static fn (array $a, array $b): int => strcmp($a['target'], $b['target']));
        sort($targets);
        sort($onSiteTargets);

        return [$entries, $targets, $onSiteEntries, $onSiteTargets];
    }

    /**
     * Whether the site serves this target: `online/<target>` exists. A pending take-offline request is
     * still served (the page stays public until a reviewer decides), a pending online request is not on
     * the site yet.
     */
    private function mirrored(string $target): bool
    {
        return $this->review->isLive($target);
    }

    /** How many entries each author has that are not on the site (internal, requested, never approved). */
    private function countsByOwner(): array
    {
        $counts = [];
        try {
            foreach ($this->articles->findAll() as $row) {
                $owner = trim((string)$row->getOwnerUid());
                if ($owner === '' || $this->review->isLive((string)$row->getPath())) {
                    continue;
                }
                // the skeleton's own posts have no owner and are not an author's drafts
                if (!str_starts_with('/' . ltrim((string)$row->getPath(), '/'), '/' . $this->areas->targetPrefix($owner) . '/')) {
                    continue;
                }
                $counts[$owner] = ($counts[$owner] ?? 0) + 1;
            }
        } catch (\Throwable) {
            return [];
        }

        return $counts;
    }
}
