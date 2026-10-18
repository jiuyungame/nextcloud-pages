<?php

declare(strict_types=1);

namespace OCA\Pages\Service;

use OCA\Pages\Db\ArticleMapper;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IAppData;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IConfig;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * The one-shot reset of all author content.
 *
 * The app changed what an author's files *are*: they were posts (a file name carried a date, the site
 * rendered them as posts) and they are template pages now. Rather than reinterpret the old files, the
 * approved decision is to discard them - the site owner starts from an empty author area - and this
 * class is the only place that does it, once, at boot:
 *
 *   1. snapshot the review manifest and tar every author's `Pages/**` into the app's own storage;
 *   2. delete every file and folder *inside* each author's `Pages/` (the folder itself stays);
 *   3. drop the author-area entries from the manifest (`entries` and `published`) and from the cache
 *      tables - the site's own entries are not touched;
 *   4. write the flag **last**.
 *
 * The order is the guarantee: an interrupted run leaves the flag unset, so the next boot retries from
 * what is left, and a completed run is a no-op forever after (a second boot writes neither the
 * configuration nor the manifest, which is what makes the two byte-identical). The snapshot goes first
 * because step 2 destroys the only copy of the files.
 */
final class AuthorContentReset
{
    /** The appconfig flag: once set, the reset never runs again. */
    public const FLAG = 'author_reset_v1';

    /** The folder inside the app's own storage that holds the backup of one run. */
    public const BACKUP_PREFIX = 'reset-';

    /** Recursion guard for the snapshot walk; deeper than this is not an author folder any more. */
    private const MAX_DEPTH = 16;

    /** Tar works in 512-byte blocks, and an archive ends with two empty ones. */
    private const TAR_BLOCK = 512;

    public function __construct(
        private IConfig $config,
        private SiteRegistry $registry,
        private FileService $files,
        private ReviewStore $store,
        private ArticleMapper $articles,
        private AuthorSourcesService $authorSources,
        private IAppData $appData,
        private LoggerInterface $logger,
        private IL10N $l10n,
    ) {
    }

    /**
     * Runs the reset once. Never throws: this runs from boot(), and a request must not fail over a
     * cleanup; every problem is logged and leaves the flag unset so the next start retries.
     */
    public function run(): void
    {
        if ($this->config->getAppValue('pages', self::FLAG, '') === '1') {
            return;
        }
        try {
            $this->reset();
        } catch (\Throwable $e) {
            $this->logger->error('pages: the author content reset failed and will retry', ['exception' => $e]);
        }
    }

    /** @throws \Throwable when the backup or the manifest cannot be written */
    private function reset(): void
    {
        $root = $this->registry->getAuthorRoot();
        $uids = $this->authorUids();
        $before = $this->store->read();

        // the snapshot is taken before anything is touched, and it doubles as the count of what the
        // reset removes
        $snapshots = [];
        foreach ($uids as $uid) {
            $snapshots[$uid] = $this->snapshot($uid);
        }
        $backup = $this->backup($snapshots);

        $removed = 0;
        $failed = [];
        foreach (array_keys($snapshots) as $uid) {
            try {
                $removed += $this->clearArea($uid);
            } catch (\Throwable $e) {
                // one unreadable area must not keep the others from being reset; the flag stays unset,
                // so the next boot tries this account again
                $failed[] = $uid;
                $this->logger->warning('pages: the author area could not be reset', [
                    'uid' => $uid,
                    'exception' => $e,
                ]);
            }
        }

        $dropped = $this->dropAuthorEntries($root, $uids);
        $after = $this->store->read();

        $this->logger->info('pages: author content reset finished', [
            'backup' => $backup,
            'authors' => count($uids),
            'files_backed_up' => array_sum(array_map('count', $snapshots)),
            'nodes_removed' => $removed,
            'entries_before' => count($before['entries']),
            'entries_after' => count($after['entries']),
            'published_before' => count($before['published']),
            'published_after' => count($after['published']),
            'keys_dropped' => $dropped,
            'failed' => $failed,
        ]);

        if ($failed !== []) {
            // the flag is deliberately not written: the accounts above still hold content, and a
            // half-reset install must not look finished
            return;
        }

        $this->config->setAppValue('pages', self::FLAG, '1');
    }

    /**
     * Every user whose author area is cleared: the participating authors and the owners the manifest
     * remembers. A user who is no longer granted may still own files (removing a grant does not delete
     * them), and those are exactly the files a reset has to take with it.
     *
     * @return list<string>
     */
    private function authorUids(): array
    {
        $uids = [];
        try {
            foreach ($this->authorSources->authors() as $uid) {
                if ($uid !== '') {
                    $uids[$uid] = true;
                }
            }
        } catch (\Throwable $e) {
            // a directory the app cannot read is not a reason to skip the reset: the manifest below
            // still names the owners whose files are there
            $this->logger->warning('pages: the author list could not be read for the content reset', [
                'exception' => $e,
            ]);
        }
        foreach ($this->store->read()['entries'] as $path => $entry) {
            $uid = trim((string)($entry['owner'] ?? ''));
            if ($uid === '') {
                // the retired key shape names the owner in the path itself
                $uid = (string)(self::legacyUid((string)$path) ?? '');
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
     * The manifest and one tar per author, in one folder of the app's own storage. The path is
     * returned (and logged) so an administrator can find it; a backup that cannot be written aborts
     * the reset, because the files it would delete have no other copy.
     *
     * @param array<string,array<string,string>> $snapshots uid => (relative path => bytes)
     * @return string `appdata_<instanceid>/pages/reset-<stamp>/`
     * @throws \RuntimeException when the folder cannot be created
     */
    private function backup(array $snapshots): string
    {
        $folder = $this->backupFolder(self::BACKUP_PREFIX . date('Ymd-His'));
        $folder->newFile(ReviewStore::FILE, $this->store->json());
        foreach ($snapshots as $uid => $files) {
            if ($files === []) {
                continue;
            }
            // one tar per author: an author's own files are what an administrator restores together
            $folder->newFile('pages-' . self::safeName($uid) . '.tar', self::tar($files));
        }

        return 'appdata pages/' . $folder->getName() . '/';
    }

    /** @throws \RuntimeException when the folder cannot be created */
    private function backupFolder(string $name): ISimpleFolder
    {
        $base = $this->appData->getFolder('/');
        $candidate = $name;
        $suffix = 1;
        while ($this->folderExists($base, $candidate)) {
            $suffix++;
            $candidate = $name . '-' . $suffix;
        }
        try {
            return $base->newFolder($candidate);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                $this->l10n->t(
                    'The backup folder %s could not be created, so nothing was deleted: %s',
                    [$candidate, $e->getMessage()],
                ),
                0,
                $e,
            );
        }
    }

    /** Whether a folder of that name already exists in the app's own storage. */
    private function folderExists(ISimpleFolder $base, string $name): bool
    {
        try {
            $base->getFolder($name);

            return true;
        } catch (\OCP\Files\NotFoundException) {
            return false;
        }
    }

    /**
     * One author's area as `relative path => bytes`, read through the Files API (so the bytes are the
     * ones the site would publish). An area that is not there is an empty snapshot: a user who never
     * wrote anything has nothing to back up.
     *
     * @return array<string,string>
     */
    private function snapshot(string $uid): array
    {
        $area = $this->files->existingFolder($uid, SiteRegistry::AUTHOR_AREA);
        if ($area === null) {
            return [];
        }
        $files = [];
        $this->walk($area, '', 1, $files);

        return $files;
    }

    /** @param array<string,string> $files */
    private function walk(Folder $folder, string $prefix, int $depth, array &$files): void
    {
        if ($depth > self::MAX_DEPTH) {
            return;
        }
        foreach ($folder->getDirectoryListing() as $child) {
            $rel = $prefix === '' ? $child->getName() : $prefix . '/' . $child->getName();
            if ($child instanceof Folder) {
                $this->walk($child, $rel, $depth + 1, $files);
                continue;
            }
            if ($child instanceof File) {
                $files[$rel] = $child->getContent();
            }
        }
    }

    /**
     * Empties one author's area: every file and folder *inside* `Pages/`, never `Pages/` itself (a
     * folder that is gone would have to be recreated by the next save, and an author's area existing is
     * the app's normal state).
     *
     * @return int the number of top-level nodes removed
     * @throws \Throwable when the area cannot be read or a child cannot be deleted
     */
    private function clearArea(string $uid): int
    {
        $area = $this->files->existingFolder($uid, SiteRegistry::AUTHOR_AREA);
        if ($area === null) {
            return 0;
        }
        $children = $area->getDirectoryListing();
        foreach ($children as $child) {
            $child->delete();
        }

        return count($children);
    }

    /**
     * Removes the author-area entries from the manifest and from the two cache tables; the site's own
     * entries are left exactly as they are.
     *
     * @param list<string> $uids
     * @return int the number of manifest keys that were dropped
     */
    private function dropAuthorEntries(string $root, array $uids): int
    {
        $manifest = $this->store->read();
        $keys = [];
        foreach (array_keys($manifest['entries'] + $manifest['published']) as $path) {
            if (self::isAuthorKey((string)$path, $root, $uids)) {
                $keys[] = (string)$path;
            }
        }
        if ($keys !== []) {
            // entries (and the article rows they cached), then the published list of the same paths:
            // the site still serves them until a publish carries the removal, which is the sweep's job
            $this->store->forgetArticle($keys);
            $this->store->forget($keys);
        }
        // rows the manifest never knew (a file added through WebDAV, never submitted) would keep their
        // owner on the app's author list; the files behind them are gone now
        foreach ($this->articles->findAll() as $row) {
            if (self::isAuthorKey((string)$row->getPath(), $root, $uids)) {
                $this->articles->deleteById((int)$row->getId());
            }
        }

        return count($keys);
    }

    /**
     * Whether a manifest key names content of an author area rather than the site's own.
     *
     * Public and static because it is the reset's only rule, and the store probe asserts it directly:
     * everything under the configured author root is author content, and so is the retired
     * `_posts/<uid>/…` namespace - but never `_posts/<file>.md`, which is a post of the site itself.
     *
     * @param list<string> $uids the authors being reset
     */
    public static function isAuthorKey(string $key, string $authorRoot, array $uids): bool
    {
        $key = ltrim(trim(str_replace('\\', '/', $key)), '/');
        if ($key === '') {
            return false;
        }
        $root = trim(str_replace('\\', '/', $authorRoot), '/');
        if ($root !== '' && str_starts_with($key, $root . '/')) {
            return true;
        }
        $legacy = self::legacyUid($key);

        return $legacy !== null && in_array($legacy, $uids, true);
    }

    /** The uid a retired `_posts/<uid>/<file>` key names, or null when the key is not one. */
    private static function legacyUid(string $key): ?string
    {
        $key = ltrim($key, '/');
        $prefix = AuthorAreaService::LEGACY_PREFIX . '/';
        if (!str_starts_with($key, $prefix)) {
            return null;
        }
        $rest = substr($key, strlen($prefix));
        $uid = strstr($rest, '/', true);

        return $uid === false || $uid === '' ? null : $uid;
    }

    /** A uid as a path segment of a backup file name. */
    private static function safeName(string $uid): string
    {
        $name = (string)preg_replace('/[^A-Za-z0-9._-]+/', '-', $uid);

        return $name === '' ? 'author' : $name;
    }

    /**
     * A ustar archive of `name => contents`.
     *
     * Written by hand instead of with PharData: creating an archive is refused on installs where
     * `phar.readonly` is on, and a backup that silently does not happen is worse than the header
     * writing below. No compression, so the archive can be read with `tar -tf` and `tar -xf` anywhere.
     *
     * @param array<string,string> $files
     */
    public static function tar(array $files): string
    {
        $archive = '';
        foreach ($files as $name => $bytes) {
            $name = ltrim(str_replace('\\', '/', (string)$name), '/');
            $bytes = (string)$bytes;
            $archive .= self::tarHeader($name, strlen($bytes)) . $bytes;
            $remainder = strlen($bytes) % self::TAR_BLOCK;
            if ($remainder !== 0) {
                $archive .= str_repeat("\0", self::TAR_BLOCK - $remainder);
            }
        }

        return $archive . str_repeat("\0", self::TAR_BLOCK * 2);
    }

    /** One 512-byte ustar header for a regular file. */
    private static function tarHeader(string $name, int $size): string
    {
        $prefix = '';
        if (strlen($name) > 100) {
            // ustar splits a long path into a 155-byte prefix and a 100-byte name, at a slash
            $cut = strrpos(substr($name, 0, 155), '/');
            if ($cut !== false && strlen(substr($name, $cut + 1)) <= 100) {
                $prefix = substr($name, 0, $cut);
                $name = substr($name, $cut + 1);
            } else {
                $name = substr($name, -100);
            }
        }
        $header = str_pad($name, 100, "\0")
            . self::octal(0644, 8)
            . self::octal(0, 8)
            . self::octal(0, 8)
            . self::octal($size, 12)
            . self::octal(time(), 12)
            . str_repeat(' ', 8)                 // the checksum field itself counts as spaces
            . '0'                                // regular file
            . str_repeat("\0", 100)              // link name
            . "ustar\0" . '00'                   // ustar magic and version
            . str_repeat("\0", 32)               // uname
            . str_repeat("\0", 32)               // gname
            . self::octal(0, 8)
            . self::octal(0, 8)
            . str_pad($prefix, 155, "\0");
        $header = str_pad($header, self::TAR_BLOCK, "\0");
        $sum = 0;
        for ($i = 0, $length = strlen($header); $i < $length; $i++) {
            $sum += ord($header[$i]);
        }

        return substr_replace($header, self::octal($sum, 7) . "\0", 148, 8);
    }

    /** A number as the NUL-terminated octal field a tar header uses. */
    private static function octal(int $value, int $width): string
    {
        return str_pad(decoct($value), $width - 1, '0', STR_PAD_LEFT) . "\0";
    }
}
