<?php

declare(strict_types=1);

namespace OCA\Pages\Service;

use OCA\Pages\Db\Article;
use OCP\Files\IAppData;
use OCP\IConfig;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * The one-shot migration to the author layout v2.
 *
 * The layout changed once, and this class is the only place that knows both sides of it:
 *
 *  - the author area is `Pages/` in every author's own files (it used to be a configurable
 *    `Pages/posts`), and its whole tree is mirrored into `<author_root>/<uid>/…` in the site;
 *  - a file therefore moves from `Pages/posts/<file>` to `Pages/<file>` (Nextcloud's own `move()`,
 *    so versions and the trash keep working), and the review manifest's keys move with it.
 *
 * Everything is idempotent: the appconfig flag `pages.author_layout_v2` is written **last**, and a run
 * that finds it does nothing at all - no config write, no manifest write, so a second start leaves
 * `config:list pages` and the manifest byte-identical. If a move fails the flag stays unset, the next
 * start retries (a file that already moved is simply not there any more), and the failure is logged.
 *
 * The manifest is snapshotted before anything is touched. The snapshot goes to the bridge's state
 * directory (`<state_dir>/review-before-v2-<stamp>.json`, the place an administrator looks for the
 * bridge's own files); if that directory cannot be written from the web server, the app's own appdata
 * is used instead and the chosen path is logged - a snapshot taken somewhere is worth more than a
 * migration that refuses to start.
 */
final class AuthorLayoutMigration
{
    /** The appconfig flag: once set, the migration never runs again. */
    public const FLAG = 'author_layout_v2';

    /** The retired area, relative to the author's `Pages/` folder. */
    public const LEGACY_FOLDER = 'posts';

    /** The bridge's default state directory; the configured one wins when the app pushed it. */
    private const DEFAULT_STATE_DIR = '/var/lib/pages/state';

    public function __construct(
        private IConfig $config,
        private SiteRegistry $registry,
        private FileService $files,
        private ReviewStore $store,
        private AuthorSourcesService $authorSources,
        private IAppData $appData,
        private LoggerInterface $logger,
        private IL10N $l10n,
    ) {
    }

    /**
     * Runs the migration once. Never throws: this runs from boot(), and a request must not fail over
     * a layout change; every problem is logged and leaves the flag unset so the next start retries.
     */
    public function run(): void
    {
        if ($this->config->getAppValue('pages', self::FLAG, '') === '1') {
            return;
        }
        try {
            $this->migrate();
        } catch (\Throwable $e) {
            // boot() must not fail over a layout change: the flag stays unset, so the next request
            // tries again, and this log line is what an administrator gets to see meanwhile
            $this->logger->error('pages: the author layout v2 migration failed and will retry', ['exception' => $e]);
        }
    }

    /** @throws \Throwable when the manifest or the configuration cannot be written */
    private function migrate(): void
    {
        $root = $this->registry->getAuthorRoot();
        $manifest = $this->store->read();
        $snapshot = $this->snapshot();
        $this->logger->info('pages: author layout v2 migration started', [
            'author_root' => $root,
            'snapshot' => $snapshot,
            'entries_before' => count($manifest['entries']),
            'published_before' => count($manifest['published']),
        ]);

        $failures = $this->moveFiles();
        if ($failures > 0) {
            // the manifest is left for the next attempt: the files it would point at are not all in
            // place, and a retry can still complete both halves
            $this->logger->warning('pages: author layout v2 migration could not move every file; it will retry', [
                'failures' => $failures,
            ]);

            return;
        }

        $this->rewriteManifest($root);
        // the retired setting goes, and the new one is written even when it was never set: an
        // administrator reading config:list pages has to see the value the app actually uses
        $this->registry->setAuthorRoot($root);
        $this->registry->clearAuthorArea();
        $this->config->setAppValue('pages', self::FLAG, '1');
        $this->logger->info('pages: author layout v2 migration finished', ['author_root' => $root]);
    }

    /**
     * Moves every `Pages/posts/<file>` to `Pages/<file>`, at any depth. A destination that already
     * exists keeps the file that is there (the author's newer copy) and the incoming one is renamed
     * with a `.migrated` suffix, which is logged: silently overwriting either file would lose data.
     *
     * @return int the number of files that could not be moved
     */
    private function moveFiles(): int
    {
        $failures = 0;
        foreach ($this->authorUids() as $uid) {
            if (!$this->files->siteExists($uid, SiteRegistry::AUTHOR_AREA)) {
                continue;
            }
            try {
                $tree = $this->files->tree($uid, SiteRegistry::AUTHOR_AREA);
            } catch (\Throwable $e) {
                $failures++;
                $this->logger->warning('pages: the author area could not be read during the layout migration', [
                    'uid' => $uid,
                    'exception' => $e,
                ]);
                continue;
            }
            $prefix = self::LEGACY_FOLDER . '/';
            $files = [];
            $dirs = [];
            foreach ($tree['nodes'] as $node) {
                $rel = ltrim((string)($node['path'] ?? ''), '/');
                if ($rel === '' || !str_starts_with($rel, $prefix)) {
                    continue;
                }
                $rest = substr($rel, strlen($prefix));
                if ($rest === '') {
                    continue;
                }
                if (($node['type'] ?? '') === 'dir') {
                    $dirs[] = $rest;
                } else {
                    $files[] = $rest;
                }
            }
            // deepest first: a file has to leave before the folder that holds it can be emptied
            usort($files, static fn (string $a, string $b): int => substr_count($b, '/') <=> substr_count($a, '/'));
            foreach ($files as $rest) {
                $from = self::LEGACY_FOLDER . '/' . $rest;
                if ($this->moveOne($uid, $from, $rest) !== '') {
                    $failures++;
                }
            }
            $this->deleteEmptyFolder($uid, self::LEGACY_FOLDER);
        }

        return $failures;
    }

    /**
     * One file of the old area to its place in the new one. Returns '' on success (or when the source
     * is already gone, which is the retry case) and a description of the failure otherwise.
     */
    private function moveOne(string $uid, string $from, string $to): string
    {
        try {
            if (!$this->files->fileExists($uid, SiteRegistry::AUTHOR_AREA, $from)) {
                // moved by an earlier attempt, or removed by its author: nothing to do
                return '';
            }
            $target = $to;
            $suffix = 0;
            while ($this->destinationExists($uid, $target)) {
                $suffix++;
                $target = $suffix === 1 ? $to . '.migrated' : $to . '.migrated' . $suffix;
            }
            if ($target !== $to) {
                $this->logger->warning('pages: the author layout migration kept the file that already held the destination', [
                    'uid' => $uid,
                    'from' => $from,
                    'kept' => $to,
                    'moved_as' => $target,
                ]);
            }
            $this->files->rename($uid, SiteRegistry::AUTHOR_AREA, $from, $target);
            $this->logger->info('pages: author layout migration moved a file', [
                'uid' => $uid,
                'from' => $from,
                'to' => $target,
            ]);

            return '';
        } catch (\Throwable $e) {
            $this->logger->warning('pages: a file could not be moved by the author layout migration', [
                'uid' => $uid,
                'from' => $from,
                'to' => $to,
                'exception' => $e,
            ]);

            return $e->getMessage();
        }
    }

    private function destinationExists(string $uid, string $rel): bool
    {
        try {
            return $this->files->fileExists($uid, SiteRegistry::AUTHOR_AREA, $rel)
                || $this->files->siteExists($uid, SiteRegistry::AUTHOR_AREA . '/' . $rel);
        } catch (\Throwable) {
            return false;
        }
    }

    /** Removes the retired `posts/` folder when nothing is left in it; anything else is kept. */
    private function deleteEmptyFolder(string $uid, string $rel): void
    {
        try {
            if (!$this->files->siteExists($uid, SiteRegistry::AUTHOR_AREA . '/' . $rel)) {
                return;
            }
            $folder = $this->files->existingFolder($uid, SiteRegistry::AUTHOR_AREA . '/' . $rel);
            if ($folder !== null && $folder->getDirectoryListing() === []) {
                $folder->delete();
                $this->logger->info('pages: the retired author posts folder was empty and has been removed', ['uid' => $uid]);
            }
        } catch (\Throwable $e) {
            // an undeletable empty folder is cosmetic: the area is mirrored either way
            $this->logger->debug('pages: the retired author posts folder could not be removed', [
                'uid' => $uid,
                'exception' => $e,
            ]);
        }
    }

    /**
     * Re-keys the manifest when the administrator moves the author content directory: a key the site
     * approved under the old directory names the same file under the new one, so the decision has to
     * follow it - otherwise every approved file would read as a draft again and the mirror would drop
     * it. The old targets stay in the last pushed list, which is what makes the mirror delete the old
     * copies on the next push.
     */
    public function rekeyAuthorRoot(string $oldRoot, string $newRoot): void
    {
        $old = trim($oldRoot, '/');
        $new = trim($newRoot, '/');
        if ($old === '' || $new === '' || $old === $new) {
            return;
        }
        $note = $this->l10n->t('Merged into the entry that already existed at this path when the author content directory changed.');
        $this->store->mutate(static function (array $manifest) use ($old, $new, $note): array {
            $entries = [];
            foreach (($manifest['entries'] ?? []) as $path => $entry) {
                $path = (string)$path;
                $mapped = str_starts_with($path, $old . '/') ? $new . substr($path, strlen($old)) : $path;
                if (isset($entries[$mapped])) {
                    $entry = [...$entry, 'state' => Article::STATE_INTERNAL, 'note' => $note];
                }
                $entries[$mapped] = $entry;
            }
            $published = [];
            foreach (($manifest['published'] ?? []) as $path => $entry) {
                $path = (string)$path;
                $mapped = str_starts_with($path, $old . '/') ? $new . substr($path, strlen($old)) : $path;
                if (!isset($published[$mapped])) {
                    $published[$mapped] = $entry;
                }
            }
            $manifest['entries'] = $entries;
            $manifest['published'] = $published;

            return $manifest;
        });
        $this->logger->info('pages: the review manifest was re-keyed for a new author content directory', [
            'from' => $old,
            'to' => $new,
        ]);
    }

    /**
     * Rewrites the manifest keys of both sections from `_posts/<uid>/<file>` to
     * `<author_root>/<uid>/<file>`. When the new key already exists the first one wins and the other
     * entry is downgraded to `internal` with a note that says why - two records of one path cannot both
     * be true, and `internal` is the state that hides nothing and decides nothing.
     */
    private function rewriteManifest(string $root): void
    {
        $before = $this->store->read();
        $note = $this->l10n->t('Merged into the entry that already existed at this path by the author layout migration.');
        $this->store->mutate(static function (array $manifest) use ($root, $note): array {
            $entries = [];
            foreach (($manifest['entries'] ?? []) as $path => $entry) {
                $mapped = self::rekey((string)$path, $root);
                if (isset($entries[$mapped])) {
                    $entry = [...$entry, 'state' => Article::STATE_INTERNAL, 'note' => $note];
                }
                $entries[$mapped] = $entry;
            }
            $published = [];
            foreach (($manifest['published'] ?? []) as $path => $entry) {
                $mapped = self::rekey((string)$path, $root);
                if (!isset($published[$mapped])) {
                    $published[$mapped] = $entry;
                }
            }
            $manifest['entries'] = $entries;
            $manifest['published'] = $published;

            return $manifest;
        });
        $after = $this->store->read();
        $this->logger->info('pages: author layout v2 migration rewrote the review manifest', [
            'entries_before' => count($before['entries']),
            'entries_after' => count($after['entries']),
            'published_before' => count($before['published']),
            'published_after' => count($after['published']),
        ]);
    }

    /** `_posts/<uid>/<file>` → `<root>/<uid>/<file>`; anything else is left exactly as it is. */
    private static function rekey(string $path, string $root): string
    {
        if (preg_match('#^_posts/([^/]+)/(.+)$#', ltrim($path, '/'), $match) !== 1) {
            return $path;
        }

        return $root . '/' . $match[1] . '/' . $match[2];
    }

    /**
     * Every user whose `Pages/posts` folder may hold files: the participating authors, plus the owners
     * the manifest still remembers (a user who was removed from the authoring grant keeps their files,
     * and their entries have to keep pointing at them).
     *
     * @return list<string>
     */
    private function authorUids(): array
    {
        $uids = [];
        try {
            foreach ($this->authorSources->authors() as $uid) {
                $uids[$uid] = true;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('pages: the author list could not be read during the layout migration', ['exception' => $e]);
        }
        foreach ($this->store->read()['entries'] as $path => $entry) {
            $uid = trim((string)($entry['owner'] ?? ''));
            if ($uid === '' && preg_match('#^_posts/([^/]+)/#', (string)$path, $match) === 1) {
                $uid = $match[1];
            }
            if ($uid !== '') {
                $uids[$uid] = true;
            }
        }
        $out = array_keys($uids);
        sort($out);

        return $out;
    }

    /**
     * Writes the manifest bytes (and the author root that is about to replace them) before anything is
     * touched. Returns the path the snapshot was written to, '' when even the fallback failed.
     */
    private function snapshot(): string
    {
        $stamp = date('Ymd-His');
        $json = $this->store->json();
        $dir = $this->stateDir();
        if ($dir !== '') {
            $path = $dir . '/review-before-v2-' . $stamp . '.json';
            try {
                if (is_dir($dir) || @mkdir($dir, 0770, true)) {
                    if (@file_put_contents($path, $json) !== false) {
                        return $path;
                    }
                }
            } catch (\Throwable) {
                // fall through to the appdata copy below
            }
        }
        // the web server may not be allowed to write the bridge's state directory (the snap confines
        // it): a snapshot in the app's own storage is still a snapshot, and the path is reported
        try {
            $name = 'review-before-v2-' . $stamp . '.json';
            $this->appData->getFolder('/')->newFile($name, $json);
            $path = 'appdata pages/' . $name;
            $this->logger->warning('pages: the manifest snapshot went to the app storage, not to the bridge state directory', [
                'path' => $path,
                'wanted' => $dir,
            ]);

            return $path;
        } catch (\Throwable $e) {
            $this->logger->error('pages: the review manifest could not be snapshotted before the layout migration', [
                'exception' => $e,
            ]);

            return '';
        }
    }

    /** The bridge's state directory, as the administrator configured it (or its default). */
    private function stateDir(): string
    {
        $global = $this->registry->getGlobal();
        $dir = trim((string)($global['state_dir'] ?? ''));

        return $dir !== '' ? rtrim($dir, '/') : self::DEFAULT_STATE_DIR;
    }
}
