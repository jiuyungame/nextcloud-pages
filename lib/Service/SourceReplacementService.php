<?php

declare(strict_types=1);

namespace OCA\Pages\Service;

use OCP\Files\Folder;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Complete replacement of the Nextcloud site folder, with a backup taken first.
 *
 * This is the **one** writer for content that comes from outside Nextcloud: the tarball Import and
 * the git Pull both end here, and they differ only in where the bytes came from. There is no merge,
 * no rebase and no "only the changed files" mode on purpose - the folder is replaced whole, so the
 * Nextcloud folder keeps being the single source of truth and cannot end up in a state that is half
 * of two histories.
 *
 * Two invariants make the operation safe to run on a live site:
 *
 *  - the **backup** is written into the app's own appdata first (a timestamped copy of every file and
 *    of a small manifest), so a replacement that turns out to be wrong can be undone by hand;
 *  - every write goes through the Nextcloud file API (FileService -> Node), never through the data
 *    directory: the file cache, versions, trash and quota keep working;
 *  - the replacement is **all or nothing**: a write that still fails after a short lock retry is
 *    undone from that backup before the error is reported, and the error says whether the undo
 *    worked ({@see SourceReplacementException});
 *  - a lock on the folder itself (the folder is rebuilt when a child cannot be deleted) is retried
 *    for the whole replacement, because Nextcloud locks are held for a moment, not for the duration
 *    of a request ({@see REPLACE_RETRY_DELAYS_US}).
 */
class SourceReplacementService
{
    /** Files a backup keeps at most; a folder above this is refused instead of copied. */
    public const MAX_BACKUP_FILES = 20000;

    /** Where backups live inside the app's own appdata folder. */
    public const BACKUP_DIR = 'source-backups';

    /** How often one write is retried while a lock is held elsewhere, and how long it waits. */
    private const WRITE_RETRY_DELAYS_US = [50_000, 150_000, 300_000];

    /**
     * How often the whole replacement is retried when the *folder* is locked, and how long it waits.
     *
     * This is a second layer below {@see WRITE_RETRY_DELAYS_US}: a file write mostly contends for its
     * parent folder, while the folder rebuild in {@see clear()} can itself find the site folder or its
     * parent locked. Both are short Nextcloud locks, so the replacement starts over (backup out of the
     * loop, the folder re-fetched) instead of reporting a failure the next second would not have.
     */
    private const REPLACE_RETRY_DELAYS_US = [100_000, 300_000, 900_000];

    /** The deepest folder the backup walks. */
    private const MAX_DEPTH = 20;

    public function __construct(
        private IAppData $appData,
        private FileService $files,
        private IL10N $l10n,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Replace the whole site folder: back it up, empty it, write the given tree.
     *
     * The replacement is atomic in the only sense a folder can be: when anything goes wrong after the
     * backup was taken, the backup is put back. A failure inside the write loop used to leave the
     * folder half emptied and half written - the worst of both states - so the rollback is part of the
     * operation, not an afterthought; the exception says whether it worked.
     *
     * The backup is taken **once**, before the retry loop: a retried replacement must not pile up one
     * backup per attempt, and the backup has to describe the folder as it was before the first attempt.
     * Every attempt re-fetches the folder through ensureSiteFolder(), because a failed folder rebuild in
     * clear() - or a retry after it - can leave the folder deleted and re-created.
     *
     * A LockedException is the one failure that is retried: Nextcloud locks are held for a moment, and
     * the folder-level lock is as short-lived as the per-file one, so up to count(REPLACE_RETRY_DELAYS_US)
     * further attempts follow. Every other Throwable fails at once and skips the remaining attempts; the
     * rollback outcome is reported for both paths.
     *
     * @param string                $ncUser the storage user of the site
     * @param string                $ncPath the site folder, relative to that user's files
     * @param array<string,string>  $files  site-relative path => bytes, already validated
     * @param string                $reason 'import' or 'pull', recorded in the backup
     * @return array{backup:array<string,mixed>,files:int,bytes:int}
     * @throws SourceReplacementException when the replacement failed; carries the backup and the
     *                                    rollback outcome
     */
    public function replace(string $ncUser, string $ncPath, array $files, string $reason): array
    {
        $backup = $this->backup($ncUser, $ncPath, $reason);

        for ($attempt = 0; ; $attempt++) {
            try {
                // re-fetched per attempt: a previous clear() may have deleted and rebuilt this folder
                $folder = $this->files->ensureSiteFolder($ncUser, $ncPath);
                $this->clear($folder);

                $bytes = 0;
                foreach ($files as $rel => $content) {
                    // `create` is true: after clear() every target is new, and a surviving node (a folder
                    // that held nothing) must not turn a write into a conflict
                    $this->writeWithRetry($ncUser, $ncPath, $rel, $content);
                    $bytes += strlen($content);
                }

                return ['backup' => $backup, 'files' => count($files), 'bytes' => $bytes];
            } catch (\OCP\Lock\LockedException $e) {
                if ($attempt >= count(self::REPLACE_RETRY_DELAYS_US)) {
                    // out of attempts: the folder-level lock is the failure the caller has to see
                    $rollbackOk = $this->rollback($ncUser, $ncPath, $backup, $e);

                    throw new SourceReplacementException($e->getMessage(), $backup, $rollbackOk, $e);
                }
                // logged, so a slow replacement is traceable to lock contention rather than size
                $this->logger->warning('pages: the site folder was locked, retrying the replacement', [
                    'app' => 'pages',
                    'path' => $ncPath,
                    'attempt' => $attempt + 1,
                    'delay_ms' => intdiv(self::REPLACE_RETRY_DELAYS_US[$attempt], 1000),
                    'exception' => $e,
                ]);
                usleep(self::REPLACE_RETRY_DELAYS_US[$attempt]);
            } catch (\Throwable $e) {
                // Either the whole new content is in place or the old one is: a failure here (a full
                // quota, no permission) is not "mostly done", and a folder that is part new and part
                // old is not a state any caller can work with - so undo before reporting. A non-lock
                // failure is not retried: waiting would not make it succeed.
                $rollbackOk = $this->rollback($ncUser, $ncPath, $backup, $e);

                throw new SourceReplacementException($e->getMessage(), $backup, $rollbackOk, $e);
            }
        }
    }

    /**
     * One write, retried a bounded number of times while the storage lock is held elsewhere.
     *
     * Nextcloud locks a target's *parent* folder while a file is created, and the site folder's parent
     * (`/Sites`) is shared with every other site operation: a concurrent request can hold it for a
     * moment and throw LockedException from an otherwise healthy write. Such a lock is short-lived by
     * nature, so a few short waits turn a spurious failure into a success; when they do not, the failure
     * reaches replace(), which retries the whole replacement and finally rolls back.
     */
    private function writeWithRetry(string $ncUser, string $ncPath, string $rel, string $content): void
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                $this->files->write($ncUser, $ncPath, $rel, $content, null, true);

                return;
            } catch (\OCP\Lock\LockedException $e) {
                if ($attempt >= count(self::WRITE_RETRY_DELAYS_US)) {
                    throw $e;
                }
                // logged so a slow replacement is traceable to lock contention rather than size
                $this->logger->warning('pages: a write was locked, retrying', [
                    'app' => 'pages',
                    'path' => $rel,
                    'retry' => $attempt + 1,
                    'exception' => $e,
                ]);
                usleep(self::WRITE_RETRY_DELAYS_US[$attempt]);
            }
        }
    }

    /**
     * Put the backup back after a failed replacement.
     *
     * @param array<string,mixed> $backup the backup that was taken before the first write
     * @return bool whether the site folder is back at the backup's state
     */
    private function rollback(string $ncUser, string $ncPath, array $backup, \Throwable $cause): bool
    {
        try {
            $this->restore($ncUser, $ncPath, $backup);

            return true;
        } catch (\Throwable $restoreError) {
            // the one case a caller has to know about: the folder is in an unknown state and the
            // backup under backup['path'] is the only way back
            $this->logger->error(
                'pages: a failed source replacement could not be rolled back; the backup is the only way back',
                [
                    'app' => 'pages',
                    'backup' => $backup['path'] ?? null,
                    'nc_path' => $ncPath,
                    'failure' => $cause->getMessage(),
                    'exception' => $restoreError,
                ],
            );

            return false;
        }
    }

    /**
     * The `files` subtree of one backup: the bytes to restore. `manifest.json` sits beside it and is
     * a description, not content, so it is not restored.
     *
     * @param array<string,mixed> $backup
     */
    private function backupFiles(array $backup): ISimpleFolder
    {
        $id = (string)($backup['id'] ?? '');
        if ($id === '') {
            throw new \RuntimeException('The backup has no id; there is nothing to restore.');
        }

        return $this->backupRoot()->getFolder($id)->getFolder('files');
    }

    /**
     * Every path the backup recorded, site-relative.
     *
     * The backup's `files` tree is walked through this list instead of listing it again, because
     * SimpleFS's getDirectoryListing() returns **files only**: a nested folder is invisible there, so
     * a recursive walk would silently restore a site without its subdirectories. The list is exactly
     * what copyInto() wrote, so it is complete by construction, and the manifest is written before
     * replace() touches the folder, so it is always there when a rollback runs.
     *
     * @param array<string,mixed> $backup
     * @return list<string>
     */
    private function backupPaths(array $backup): array
    {
        $id = (string)($backup['id'] ?? '');
        if ($id === '') {
            throw new \RuntimeException('The backup has no id; there is nothing to restore.');
        }
        $manifest = json_decode(
            $this->backupRoot()->getFolder($id)->getFile('manifest.json')->getContent(),
            true,
        );
        $paths = is_array($manifest) ? ($manifest['paths'] ?? []) : [];
        if (!is_array($paths)) {
            throw new \RuntimeException('The backup manifest does not list the files it holds.');
        }

        return array_values(array_filter($paths, static fn (mixed $path): bool => is_string($path) && $path !== ''));
    }

    /**
     * Put the backup back: empty the site folder and write every file the backup recorded at the path
     * it came from. The inverse of {@see copyInto()} (minus the empty folders, which hold no bytes).
     *
     * @param array<string,mixed> $backup
     */
    private function restore(string $ncUser, string $ncPath, array $backup): void
    {
        $backupFiles = $this->backupFiles($backup);
        $paths = $this->backupPaths($backup);

        $folder = $this->files->ensureSiteFolder($ncUser, $ncPath);
        $this->clear($folder);

        foreach ($paths as $rel) {
            // create: clear() emptied the folder, so every path is new; getFile() resolves the nested
            // path inside the backup, and FileService::write() rejects a traversal attempt
            $this->files->write($ncUser, $ncPath, $rel, $backupFiles->getFile($rel)->getContent(), null, true);
        }
    }

    /**
     * Copy the whole site folder into appdata under a timestamped name.
     *
     * A missing folder is not a failure: replacing a site that has nothing in it yet has nothing to
     * back up, and the manifest still records that the backup was taken.
     *
     * @return array{id:string,path:string,files:int,bytes:int,at:int}
     */
    public function backup(string $ncUser, string $ncPath, string $reason): array
    {
        $at = time();
        $id = date('Ymd-His', $at) . '-' . preg_replace('/[^a-z0-9_-]/i', '', $reason) . '-' . substr(sha1((string)$at . $ncUser . $ncPath), 0, 6);
        $root = $this->backupRoot()->newFolder($id);
        $target = $root->newFolder('files');

        $files = 0;
        $bytes = 0;
        $source = $this->files->existingFolder($ncUser, $ncPath);
        if ($source !== null) {
            [$files, $bytes, $paths] = $this->copyInto($source, $target, '', 0);
        } else {
            $paths = [];
        }

        $root->newFile('manifest.json', json_encode([
            'id' => $id,
            'at' => $at,
            'reason' => $reason,
            'nc_user' => $ncUser,
            'nc_path' => $ncPath,
            'files' => $files,
            'bytes' => $bytes,
            'paths' => $paths,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");

        return [
            'id' => $id,
            'path' => 'appdata/pages/' . self::BACKUP_DIR . '/' . $id,
            'files' => $files,
            'bytes' => $bytes,
            'at' => $at,
        ];
    }

    /**
     * @return array{0:int,1:int,2:list<string>} files, bytes, the paths that were copied
     */
    private function copyInto(Folder $source, ISimpleFolder $target, string $prefix, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \RuntimeException($this->l10n->t('The site folder is nested too deeply to back up.'));
        }
        $files = 0;
        $bytes = 0;
        $paths = [];
        foreach ($source->getDirectoryListing() as $child) {
            $rel = $prefix === '' ? $child->getName() : $prefix . '/' . $child->getName();
            if ($child instanceof Folder) {
                $sub = $target->newFolder($child->getName());
                [$subFiles, $subBytes, $subPaths] = $this->copyInto($child, $sub, $rel, $depth + 1);
                $files += $subFiles;
                $bytes += $subBytes;
                array_push($paths, ...$subPaths);
                continue;
            }
            if (++$files > self::MAX_BACKUP_FILES) {
                throw new \RuntimeException($this->l10n->t(
                    'The site folder holds more than %s files; it was not replaced.',
                    [(string)self::MAX_BACKUP_FILES],
                ));
            }
            $content = $child->getContent();
            $target->newFile($child->getName(), $content);
            $bytes += strlen($content);
            $paths[] = $rel;
        }

        return [$files, $bytes, $paths];
    }

    /**
     * Empty the folder through the Files API, so every child lands in the trash like a delete by hand.
     *
     * A child whose lock is held elsewhere cannot be deleted one by one; the fallback replaces the
     * folder itself (delete + recreate), which reaches the same end state with one lock acquisition
     * and is what a user would do in the Files app. The path stays the same, so the site configuration
     * and the bridge keep working; only the folder's file id changes (and with it a share of the folder
     * itself - the files inside are replaced either way).
     */
    private function clear(Folder $folder): void
    {
        $parent = $folder->getParent();
        $name = $folder->getName();
        try {
            foreach ($folder->getDirectoryListing() as $child) {
                $child->delete();
            }

            return;
        } catch (\OCP\Lock\LockedException $e) {
            // logged, so the reason the id changed is traceable; the replacement itself continues
            $this->logger->warning(
                'pages: a child of the site folder is locked, replacing the folder itself',
                ['app' => 'pages', 'path' => $e->getPath(), 'exception' => $e],
            );
        }
        $folder->delete();
        $parent->newFolder($name);
    }

    private function backupRoot(): ISimpleFolder
    {
        try {
            return $this->appData->getFolder(self::BACKUP_DIR);
        } catch (NotFoundException) {
            return $this->appData->newFolder(self::BACKUP_DIR);
        }
    }
}
