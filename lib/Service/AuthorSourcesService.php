<?php

declare(strict_types=1);

namespace OCA\Pages\Service;

use OCA\Pages\Db\ArticleMapper;
use OCP\IUserManager;
use OCP\Server;

/**
 * The author sources: what the bridge has to mirror from the authors' own folders, and what state
 * each author's space is in.
 *
 * The push payload is built here rather than in the controller because it is the one place where the
 * three representations meet: the folder (what is really there), the target namespace (what the
 * published site serves) and the previous push (what the mirrors still hold from last time). Only the
 * last one can answer "what has to be deleted": a target is reported as removed when it was pushed
 * before and is not among the current entries, so the mirror is never asked to delete something this
 * app never put there, and an area that is temporarily unreadable removes nothing at all.
 *
 * Each author block carries two lists, one per bridge directory: `entries` is everything the area
 * holds (mirrored into the preview tree, so an internal page stays previewable) and `on_site` is the
 * approved subset (mirrored into the commit tree, the only tree a publish commits from).
 *
 * `removed` therefore needs the app to remember what it sent; {@see SiteRegistry::setAuthorSources()}
 * holds that list, keyed by uid, and is written only after the bridge accepted the payload.
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
     * The `authors` block of the push payload, and the two target lists to remember afterwards.
     *
     * `$previous` is what the last successful push sent; pass it (or read it with previous()) so that
     * `removed` can be computed. Without it nothing is ever reported as removed, which is the safe
     * direction: a missing deletion leaves a stale file in the mirror, while a wrong one deletes
     * content the app cannot rebuild from its own state.
     *
     * `$drop` names targets the caller knows must leave the mirror *now*, even when `$previous` no
     * longer lists them: a push is the only way to ask for a removal, the mirror applies it when it
     * next runs, and any push in between recomputes `removed` from the remembered list - so a removal
     * asked for by the push before would silently be lost, leaving the file in the work copy for a
     * commit that can no longer stage it. A target that is already gone costs one no-op.
     *
     * Each author block carries **both** lists of the two directories: `entries` is everything the
     * author's area holds (the preview tree) and `on_site` is the approved subset (the commit tree).
     * `targets` remembers the first (that is what `removed` is computed from) and `onsite` the
     * second (that is what the publish stages removals against).
     *
     * @param array<string,list<string>>|null $previous
     * @param list<string> $drop target paths (site-relative) that have to be removed
     * @return array{authors:list<array<string,mixed>>,targets:array<string,list<string>>,onsite:array<string,list<string>>,errors:array<string,string>}
     */
    public function build(?array $previous = null, array $drop = []): array
    {
        $previous ??= $this->previous();
        // one read of the manifest for the whole push: its state decides which files are on the site
        // (and therefore which ones the commit tree may hold), and it is also where a request is
        // recorded
        $manifest = $this->review->read();
        $onSite = $this->siteTargets($manifest);
        $gone = $this->byAuthor($drop);
        $authors = [];
        $targets = [];
        $onsiteTargets = [];
        $errors = [];
        foreach ($this->authors() as $uid) {
            try {
                if (!$this->areas->areaExists($uid)) {
                    // no folder yet means no files: nothing to send, and - deliberately - nothing to
                    // remove either. A disappearing area must never turn into a mirror-wide delete.
                    continue;
                }
                [$entries, $current, $onSiteEntries, $currentOnSite] = $this->entries($uid, $onSite);
            } catch (\Throwable $e) {
                // One unreadable author must not fail the whole push, but silence would hide a broken
                // account: the message travels with the payload and reaches the admin panel.
                $errors[$uid] = $e->getMessage();
                continue;
            }
            $before = $previous[$uid] ?? [];
            $removed = array_values(array_diff($before, $current));
            foreach (array_keys($gone[$uid] ?? []) as $target) {
                if (!in_array($target, $removed, true)) {
                    $removed[] = $target;
                }
            }
            sort($removed);
            $authors[] = [
                'uid' => $uid,
                'path' => '/' . $this->areas->area(),
                'entries' => $entries,
                // the commit list: an empty array is meaningful ("nothing approved"), which is why
                // it is always sent - the bridge only falls back to `entries` when the key is absent
                'on_site' => $onSiteEntries,
                'removed' => $removed,
            ];
            $targets[$uid] = $current;
            $onsiteTargets[$uid] = $currentOnSite;
        }

        return ['authors' => $authors, 'targets' => $targets, 'onsite' => $onsiteTargets, 'errors' => $errors];
    }

    /**
     * Target paths grouped by the author they belong to; a path that names nobody is dropped, because
     * no author block would carry it. The paths are normalised to the relative form the payload uses:
     * the caller knows an article as it is stored (`/_posts/…`), and the bridge refuses an absolute or
     * otherwise unclean path.
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
     * Push the configuration **with** the author sources attached, and remember the target list only
     * once the bridge accepted it.
     *
     * Every path that changes an author's files goes through this: an author area is mirrored from the
     * list the app sends, so a file that was just added or removed stays invisible to the mirror until
     * the configuration travels again. Pushing the site without the authors block would actively drop
     * them from the bridge (the pushed site replaces the previous one).
     *
     * @param array<string,string>|null $secrets rotation payload, when the caller rotates
     * @param list<string> $drop target paths that have to leave the mirror (see build())
     * @return array{authors:int,result:array<string,mixed>,errors:array<string,string>}
     */
    public function pushWithAuthors(BridgeClient $bridge, ?array $secrets = null, array $drop = []): array
    {
        $built = $this->build(null, $drop);
        $result = $bridge->pushConfig([
            ...($secrets === null ? [] : ['secrets' => $secrets]),
            'authors' => $built['authors'],
        ]);
        // both maps describe what the bridge now holds: the full list drives `removed`, the on-site
        // subset is what a publish may stage a removal for
        $this->registry->setAuthorSources($built['targets'], $built['onsite']);

        return ['authors' => count($built['authors']), 'result' => $result, 'errors' => $built['errors']];
    }

    /** What the last successful push sent, keyed by uid. */
    public function previous(): array
    {
        $stored = $this->registry->getAuthorSources();
        $out = [];
        foreach ($stored as $uid => $list) {
            if (!is_string($uid) || !is_array($list)) {
                continue;
            }
            $out[$uid] = array_values(array_filter(array_map('strval', $list), static fn (string $v): bool => $v !== ''));
        }

        return $out;
    }

    /**
     * Every mirrored file of every author area right now, flat - the same `entries()` a push sends,
     * without the removal bookkeeping that needs the previous push.
     *
     * `occ pages:repair` re-derives the article rows from exactly this list: a file that was written
     * outside the app (the Files app, a sync client, a restored backup) has no row, and re-saving every
     * page by hand is what this replaces. It is also what makes "how much is not on the bridge yet"
     * answerable, because the difference against {@see previous()} is that set.
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
     * How much of the scanned tree the bridge has not been told about: targets that are new or changed
     * since the last accepted push (`added`) and targets that push recorded but whose file is gone
     * (`removed`). An unreadable area is reported separately and counts as nothing, so the numbers never
     * claim a completeness the scan did not have.
     *
     * `$scan` lets a caller that has just scanned (the repair command) reuse its result instead of
     * walking every author folder a second time.
     *
     * @param array{entries:list<array{uid:string,path:string,target:string,renderable:bool}>,errors:array<string,string>}|null $scan
     * @return array{added:int,removed:int,errors:array<string,string>}
     */
    public function pending(?array $scan = null): array
    {
        $scan ??= $this->scan();
        $previous = $this->previous();
        $current = [];
        foreach ($scan['entries'] as $entry) {
            $current[(string)$entry['uid']][] = (string)$entry['target'];
        }
        $added = 0;
        $removed = 0;
        foreach (array_unique(array_merge(array_keys($current), array_keys($previous))) as $uid) {
            $now = $current[$uid] ?? [];
            $before = $previous[$uid] ?? [];
            $added += count(array_diff($now, $before));
            $removed += count(array_diff($before, $now));
        }

        return ['added' => $added, 'removed' => $removed, 'errors' => $scan['errors']];
    }

    /**
     * The status table of the admin panel: one row per author, answering "is the area there, how much
     * is in it, and what did the site keep from an earlier push".
     *
     * `missing` lists the targets that were pushed before and whose source file is gone now - the
     * mirror still holds them, and the next push will delete them.
     *
     * @return list<array{uid:string,displayName:string,area:string,posts:int,files:int,missing:list<string>,unpublished:int}>
     */
    public function status(): array
    {
        $previous = $this->previous();
        $unpublished = $this->countsByOwner();
        $rows = [];
        foreach ($this->authors() as $uid) {
            $counts = $this->counts($uid);
            $missing = array_values(array_diff($previous[$uid] ?? [], $counts['targets']));
            sort($missing);
            $rows[] = [
                'uid' => $uid,
                'displayName' => $this->areas->displayName($uid),
                'area' => '/' . $this->areas->area(),
                'posts' => $counts['posts'],
                'files' => $counts['files'],
                'missing' => $missing,
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
        $manifest = $this->review->read();
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
            if ($target !== '' && $this->mirrored($target, $manifest)) {
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
     * Every file of the area is an entry of the **preview** list - an image exactly like a page. The
     * preview tree mirrors that list, so an internal page is built and therefore previewable; that
     * is the whole point of "the preview shows every file". What keeps the public site honest is
     * the commit tree, which mirrors only the **on-site** subset ({@see ReviewStore::onSite()}) and
     * the publish step, which stages exactly that set. Merging the two lists again would make
     * internal pages un-previewable; dropping the on-site list would publish them.
     *
     * The mirror syncs *to* each list, so omitting a target moves the file out of that tree without
     * moving or deleting the author's file. A target that left only the on-site list (a take-down)
     * stays in the preview tree and disappears from the commit tree at the next mirror; a target
     * that left both (a deletion) is reported in `removed`, which is the only way either tree ever
     * deletes it.
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
     * Whether the mirror may hold this target: it is approved for the site. A pending take-offline
     * request is still approved (the page stays public until a reviewer decides), a pending online
     * request is not on the site yet.
     *
     * @param array<string,mixed> $manifest
     */
    private function mirrored(string $target, array $manifest): bool
    {
        return ReviewStore::onSite($manifest, $target);
    }

    /**
     * The targets that are approved for the site, from the visibility manifest (the authority): the
     * mirror must hold a copy of exactly these, and a target this list drops from one push to the next
     * is what makes the bridge delete it from the work copy.
     *
     * @param array<string,mixed> $manifest
     * @return array<string,true>
     */
    private function siteTargets(array $manifest): array
    {
        $out = [];
        foreach ($manifest['entries'] ?? [] as $path => $entry) {
            if (ReviewStore::onSite($manifest, (string)$path)) {
                $out[(string)$path] = true;
            }
        }

        return $out;
    }

    /** How many entries each author has that are not on the site (internal, requested, never approved). */
    private function countsByOwner(): array
    {
        $counts = [];
        $manifest = $this->review->read();
        try {
            foreach ($this->articles->findAll() as $row) {
                $owner = trim((string)$row->getOwnerUid());
                if ($owner === '' || ReviewStore::onSite($manifest, (string)$row->getPath())) {
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
