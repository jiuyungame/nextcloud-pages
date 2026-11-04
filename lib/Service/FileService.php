<?php

declare(strict_types=1);

namespace OCA\Pages\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IL10N;
use OCP\IUserManager;

/**
 * Reads and writes site sources through Nextcloud's own Files API instead of letting the bridge write
 * to disk, so version history, trash, sharing and desktop sync keep working. Concurrency uses
 * Nextcloud's native ETag optimistic lock.
 */
class FileService
{
    /** Above this size a file is not offered as text in the editor. */
    public const MAX_TEXT_BYTES = 2 * 1024 * 1024;
    private const MAX_TREE_ENTRIES = 20000;
    private const MAX_TREE_DEPTH = 12;

    public function __construct(
        private IRootFolder $rootFolder,
        private IL10N $l10n,
        private IUserManager $userManager,
    ) {
    }

    private function userFolder(string $userId): Folder
    {
        // A uid does not guarantee an account: an article row can outlive the user it belonged to.
        // Callers treat a missing folder as "nothing to read", so the check turns a stale row into
        // that answer instead of the filesystem exception (which was a 500 on the configuration push).
        if ($this->userManager->get($userId) === null) {
            throw new NotFoundException($this->l10n->t('User folder not found'));
        }
        $folder = $this->rootFolder->getUserFolder($userId);
        if (!$folder instanceof Folder) {
            throw new NotFoundException($this->l10n->t('User folder not found'));
        }
        return $folder;
    }

    /** Site root folder; throws NotFoundException when it is missing. */
    public function siteFolder(string $userId, string $ncPath): Folder
    {
        $node = $this->userFolder($userId)->get($this->clean($ncPath));
        if (!$node instanceof Folder) {
            throw new NotFoundException($this->l10n->t('Site folder does not exist: %s', [$ncPath]));
        }
        return $node;
    }

    public function siteExists(string $userId, string $ncPath): bool
    {
        try {
            $this->siteFolder($userId, $ncPath);
            return true;
        } catch (NotFoundException|NotPermittedException) {
            return false;
        }
    }

    /**
     * Whether one file exists inside a folder of `$userId`'s own files.
     *
     * Separate from `siteExists()`, which answers for a *folder*: the two take different arguments,
     * and a caller that mixes them up gets "the folder is there" as the answer to "is the file there".
     */
    public function fileExists(string $userId, string $ncPath, string $relPath): bool
    {
        $rel = $this->clean($relPath);
        if ($rel === '') {
            return false;
        }
        try {
            return $this->resolve($userId, $ncPath, $rel) instanceof File;
        } catch (NotFoundException|NotPermittedException) {
            return false;
        }
    }

    /**
     * Relative-path sanitising: rejects directory traversal. This is the only path entry point, so
     * every read and write has to go through it.
     */
    private function clean(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = ltrim($path, '/');
        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                throw new NotPermittedException($this->l10n->t('Path must not contain ..'));
            }
            if (str_contains($segment, "\0")) {
                throw new NotPermittedException($this->l10n->t('Path contains an illegal character'));
            }
            $parts[] = $segment;
        }
        return implode('/', $parts);
    }

    private function resolve(string $userId, string $ncPath, string $relPath): Node
    {
        $base = $this->clean($ncPath);
        $rel = $this->clean($relPath);
        $full = $rel === '' ? $base : $base . '/' . $rel;
        return $this->userFolder($userId)->get($full);
    }

    /**
     * @return array{nodes:list<array<string,mixed>>,truncated:bool}
     */
    public function tree(string $userId, string $ncPath): array
    {
        $root = $this->siteFolder($userId, $ncPath);
        $nodes = [];
        $truncated = false;

        $walk = function (Folder $folder, string $prefix, int $depth) use (&$walk, &$nodes, &$truncated): void {
            if ($truncated) {
                return;
            }
            if ($depth > self::MAX_TREE_DEPTH) {
                $truncated = true;
                return;
            }
            $children = $folder->getDirectoryListing();
            usort($children, static function (Node $a, Node $b): int {
                $aDir = $a instanceof Folder;
                $bDir = $b instanceof Folder;
                if ($aDir !== $bDir) {
                    return $aDir ? -1 : 1;
                }
                return strcasecmp($a->getName(), $b->getName());
            });
            foreach ($children as $child) {
                if (count($nodes) >= self::MAX_TREE_ENTRIES) {
                    $truncated = true;
                    return;
                }
                $rel = $prefix === '' ? $child->getName() : $prefix . '/' . $child->getName();
                $isDir = $child instanceof Folder;
                $nodes[] = [
                    'path' => $rel,
                    'name' => $child->getName(),
                    'type' => $isDir ? 'dir' : 'file',
                    'size' => $isDir ? 0 : $child->getSize(),
                    'mtime' => $child->getMTime(),
                    'etag' => $child->getEtag(),
                    // fileid, used by the UI to build the "open in Files" link (/index.php/f/<fileid>)
                    'id' => $child->getId(),
                    // dot-prefixed entries are treated as tooling/config and collapsed in the UI
                    'hidden' => str_starts_with($child->getName(), '.'),
                ];
                if ($isDir) {
                    $walk($child, $rel, $depth + 1);
                }
            }
        };

        $walk($root, '', 1);
        return ['nodes' => $nodes, 'truncated' => $truncated];
    }

    /**
     * @return array<string,mixed>
     */
    public function read(string $userId, string $ncPath, string $relPath): array
    {
        $node = $this->resolve($userId, $ncPath, $relPath);
        if (!$node instanceof File) {
            throw new NotFoundException($this->l10n->t('Not a file: %s', [$relPath]));
        }
        $size = $node->getSize();
        if ($size > self::MAX_TEXT_BYTES) {
            throw new NotPermittedException($this->l10n->t(
                'File is too large (%s) to open in the editor (limit %s)',
                [$this->humanBytes($size), $this->humanBytes(self::MAX_TEXT_BYTES)],
            ));
        }
        $raw = $node->getContent();
        if (!mb_check_encoding($raw, 'UTF-8')) {
            throw new NotPermittedException($this->l10n->t('Binary file cannot be opened in the editor: %s', [$relPath]));
        }

        return [
            'path' => $this->clean($relPath),
            'content' => $raw,
            // the client sends this back on the next save; a mismatch means someone edited elsewhere
            'etag' => $node->getEtag(),
            'mtime' => $node->getMTime(),
            'size' => $size,
        ];
    }

    /**
     * @return array<string,mixed>
     * @throws ConflictException when the optimistic lock fails
     */
    public function write(
        string $userId,
        string $ncPath,
        string $relPath,
        string $content,
        ?string $baseEtag = null,
        bool $create = false,
    ): array {
        $rel = $this->clean($relPath);
        if ($rel === '') {
            throw new NotPermittedException($this->l10n->t('A file path is required'));
        }

        try {
            $node = $this->resolve($userId, $ncPath, $rel);
        } catch (NotFoundException) {
            if (!$create) {
                throw new NotFoundException($this->l10n->t('File does not exist: %s', [$rel]));
            }
            return $this->createFile($userId, $ncPath, $rel, $content);
        }

        if ($create) {
            // "create" must mean create only, otherwise POST /file would silently overwrite an existing file
            throw new ConflictException(
                $this->l10n->t('File already exists: %s', [$rel]),
                ['path' => $rel, 'exists' => true, 'etag' => $node->getEtag()],
            );
        }

        if (!$node instanceof File) {
            throw new NotPermittedException($this->l10n->t('Target is not a file: %s', [$rel]));
        }
        if (!$node->isUpdateable()) {
            throw new NotPermittedException($this->l10n->t('No write permission: %s', [$rel]));
        }

        $current = $node->getEtag();
        if ($baseEtag !== null && $baseEtag !== '' && $baseEtag !== $current) {
            throw new ConflictException(
                $this->l10n->t('File was modified elsewhere, reload it first'),
                ['path' => $rel, 'current_etag' => $current, 'base_etag' => $baseEtag],
            );
        }

        $node->putContent($content);
        $node->touch();
        // re-fetch the node after putContent, only then is the ETag the new one
        $fresh = $this->resolve($userId, $ncPath, $rel);

        return [
            'path' => $rel,
            'etag' => $fresh->getEtag(),
            'size' => $fresh->getSize(),
            'mtime' => $fresh->getMTime(),
            'created' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function createFile(string $userId, string $ncPath, string $rel, string $content): array
    {
        $parentPath = dirname($rel);
        $folder = $this->siteFolder($userId, $ncPath);
        if ($parentPath !== '.' && $parentPath !== '') {
            $folder = $this->ensureFolder($folder, $parentPath);
        }
        $file = $folder->newFile(basename($rel), $content);
        return [
            'path' => $rel,
            'etag' => $file->getEtag(),
            'size' => $file->getSize(),
            'mtime' => $file->getMTime(),
            'created' => true,
        ];
    }

    private function ensureFolder(Folder $base, string $relative): Folder
    {
        $current = $base;
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '') {
                continue;
            }
            try {
                $next = $current->get($segment);
                if (!$next instanceof Folder) {
                    throw new NotPermittedException($this->l10n->t('%s already exists and is not a folder', [$segment]));
                }
            } catch (NotFoundException) {
                $next = $current->newFolder($segment);
            }
            $current = $next;
        }
        return $current;
    }

    /**
     * Creates a folder (multi-level). An existing path is an error rather than a silent reuse:
     * `ensureFolder()` is the lenient helper used when creating a file needs its parents.
     *
     * @return array<string,mixed>
     */
    public function mkdir(string $userId, string $ncPath, string $relPath): array
    {
        $rel = $this->clean($relPath);
        if ($rel === '') {
            throw new NotPermittedException($this->l10n->t('A folder name is required'));
        }
        $base = $this->siteFolder($userId, $ncPath);
        try {
            $base->get($rel);
            throw new ConflictException($this->l10n->t('Folder already exists: %s', [$rel]), ['path' => $rel, 'exists' => true]);
        } catch (NotFoundException) {
            // expected path: create only when it is missing
        }
        $folder = $this->ensureFolder($base, $rel);
        return [
            'path' => $rel,
            'etag' => $folder->getEtag(),
            'mtime' => $folder->getMTime(),
            'created' => true,
        ];
    }

    /**
     * Rename / move: the same operation, moving a node to another relative path. Three cases the
     * Files API does not cover: an existing target is rejected (rename is not overwrite), a folder
     * cannot be moved into itself, and empty paths are refused (the site root cannot move).
     *
     * @return array<string,mixed>
     */
    public function rename(string $userId, string $ncPath, string $fromRel, string $toRel): array
    {
        $from = $this->clean($fromRel);
        $to = $this->clean($toRel);
        if ($from === '' || $to === '') {
            throw new NotPermittedException($this->l10n->t('Both the source and the target path are required'));
        }
        $base = $this->siteFolder($userId, $ncPath);
        $node = $base->get($from);

        if ($from === $to) {
            return [
                'from' => $from,
                'path' => $to,
                'type' => $node instanceof Folder ? 'dir' : 'file',
                'unchanged' => true,
            ];
        }

        try {
            $existing = $base->get($to);
            throw new ConflictException(
                $this->l10n->t('Target already exists: %s', [$to]),
                ['path' => $to, 'exists' => true, 'etag' => $existing->getEtag()],
            );
        } catch (NotFoundException) {
            // expected: only continue when the target is missing
        }

        if ($node instanceof Folder && str_starts_with($to . '/', $from . '/')) {
            throw new NotPermittedException($this->l10n->t('Cannot move a folder into itself: %s → %s', [$from, $to]));
        }

        // create the parent folder, consistent with a new file creating its parents
        $parent = dirname($to);
        if ($parent !== '.' && $parent !== '') {
            $this->ensureFolder($base, $parent);
        }

        // Node::move takes a path relative to the user root, so prefix the site folder path
        $node->move($base->getPath() . '/' . $to);
        $fresh = $base->get($to);

        return [
            'from' => $from,
            'path' => $to,
            'type' => $fresh instanceof Folder ? 'dir' : 'file',
            'etag' => $fresh->getEtag(),
            'mtime' => $fresh->getMTime(),
            'unchanged' => false,
        ];
    }

    public function delete(string $userId, string $ncPath, string $relPath): void
    {
        $rel = $this->clean($relPath);
        if ($rel === '') {
            throw new NotPermittedException($this->l10n->t('Refusing to delete the site root folder'));
        }
        $node = $this->resolve($userId, $ncPath, $rel);
        if (!$node->isDeletable()) {
            throw new NotPermittedException($this->l10n->t('No delete permission: %s', [$rel]));
        }
        $node->delete();
    }

    /**
     * The site folder of one user, created (with any missing parents) when it is not there yet. Used
     * where the folder has to exist before content can be written: the first author area, and the
     * replacement of a site folder that Import or Pull is about to fill.
     */
    public function ensureSiteFolder(string $userId, string $ncPath): Folder
    {
        $path = $this->clean($ncPath);
        if ($path === '') {
            throw new NotPermittedException($this->l10n->t('A site folder path is required'));
        }
        $userFolder = $this->userFolder($userId);
        try {
            $node = $userFolder->get($path);
        } catch (NotFoundException) {
            // Folder::newFolder() does not create intermediate directories, so a site folder two
            // levels down ("Sites/blog") fails on a fresh account: walk the segments and create each
            // missing level. Import, Pull and a first author area all start from such a folder.
            $folder = $userFolder;
            $prefix = '';
            foreach (explode('/', $path) as $segment) {
                $prefix = $prefix === '' ? $segment : $prefix . '/' . $segment;
                try {
                    $child = $folder->get($segment);
                } catch (NotFoundException) {
                    $child = $folder->newFolder($segment);
                }
                if (!$child instanceof Folder) {
                    throw new NotPermittedException($this->l10n->t('Target is not a folder: %s', [$prefix]));
                }
                $folder = $child;
            }

            return $folder;
        }
        if (!$node instanceof Folder) {
            throw new NotPermittedException($this->l10n->t('Target is not a folder: %s', [$ncPath]));
        }
        return $node;
    }

    /**
     * One folder inside the site folder, created (with any missing parents) when it is not there yet.
     * The site folder itself is created on the way, so this is also the "make the site folder usable"
     * call of the three fixed subdirectories (`source/`, and the two the bridge owns).
     *
     * @throws NotPermittedException when a segment of the path exists and is not a folder
     */
    public function ensureSubfolder(string $userId, string $ncPath, string $relPath): Folder
    {
        $base = $this->ensureSiteFolder($userId, $ncPath);
        $rel = $this->clean($relPath);
        if ($rel === '') {
            return $base;
        }
        $current = $base;
        $prefix = '';
        foreach (explode('/', $rel) as $segment) {
            $prefix = $prefix === '' ? $segment : $prefix . '/' . $segment;
            try {
                $next = $current->get($segment);
            } catch (NotFoundException) {
                $next = $current->newFolder($segment);
            }
            if (!$next instanceof Folder) {
                throw new NotPermittedException($this->l10n->t('Target is not a folder: %s', [$prefix]));
            }
            $current = $next;
        }

        return $current;
    }

    /**
     * The user's own files folder (`<data>/<user>/files`), resolved through the Files API. This is
     * where the quota of that user is visible: copying into it is what the quota is charged for.
     */
    public function userFilesFolder(string $userId): Folder
    {
        return $this->userFolder($userId);
    }

    /** The folder at an absolute user-root path, or null while it does not exist (creates nothing). */
    public function existingFolder(string $userId, string $ncPath): ?Folder
    {
        try {
            $node = $this->userFolder($userId)->get($this->clean($ncPath));
        } catch (NotFoundException|NotPermittedException) {
            return null;
        }
        return $node instanceof Folder ? $node : null;
    }

    /**
     * File count and total bytes of a folder tree, read through the Files API. Folders contribute no
     * bytes; every entry is returned as its own path so that a caller can spot entries that appeared
     * or vanished between two reads.
     *
     * `$limit` bounds the walk, which only a read-only report may do (preflight on a huge site):
     * `complete` is then false and the numbers are what was read until the limit was reached.
     *
     * @return array{files:int,bytes:int,entries:int,paths:list<string>,complete:bool}
     */
    public function totals(Folder $root, ?int $limit = null): array
    {
        $files = 0;
        $bytes = 0;
        $paths = [];
        $seen = 0;
        $complete = true;

        $walk = function (Folder $folder, string $prefix, int $depth) use (&$walk, &$files, &$bytes, &$paths, &$seen, &$complete, $limit): void {
            if ($depth > self::MAX_TREE_DEPTH) {
                $complete = false;
                return;
            }
            foreach ($folder->getDirectoryListing() as $child) {
                if ($limit !== null && $seen >= $limit) {
                    $complete = false;
                    return;
                }
                $isDir = $child instanceof Folder;
                $rel = $prefix === '' ? $child->getName() : $prefix . '/' . $child->getName();
                $paths[] = ($isDir ? 'd' : 'f') . ':' . $rel;
                $seen++;
                if ($isDir) {
                    $walk($child, $rel, $depth + 1);
                    continue;
                }
                $files++;
                $bytes += (int)$child->getSize();
            }
        };
        $walk($root, '', 1);
        sort($paths);
        return ['files' => $files, 'bytes' => $bytes, 'entries' => $seen, 'paths' => $paths, 'complete' => $complete];
    }

    /**
     * Whether the folder itself or one of its direct entries is shared. Shares hang off the file id,
     * so a later move invalidates them; this only feeds a warning before such a move, and a backend
     * that will not answer reports false rather than blocking the report.
     */
    public function hasShares(Folder $folder): bool
    {
        try {
            if ($folder->isShared()) {
                return true;
            }
            foreach ($folder->getDirectoryListing() as $child) {
                if ($child->isShared()) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }
        return false;
    }

    /**
     * Copies a whole folder tree to another path inside the same Nextcloud (possibly another user)
     * with Node::copy() → View::copy(), which keeps the filecache in sync. Writing through the host
     * filesystem instead would leave the new folder invisible in Files until `occ files:scan`.
     */
    public function copyFolder(Folder $source, string $targetPath): Folder
    {
        $source->copy($targetPath);
        $target = $this->rootFolder->get($targetPath);
        if (!$target instanceof Folder) {
            throw new NotPermittedException($this->l10n->t('The copy did not produce a folder: %s', [$targetPath]));
        }
        return $target;
    }

    /**
     * Removes a folder tree. A caller removes the site directory through delete() on the node instead,
     * so nothing above it is ever touched.
     */
    public function deleteFolder(Folder $folder): void
    {
        if (!$folder->isDeletable()) {
            throw new NotPermittedException($this->l10n->t('No delete permission: %s', [$folder->getName()]));
        }
        $folder->delete();
    }

    /** The same 1-decimal formatting the editor uses, so size messages read the same everywhere. */
    public function bytesLabel(int $bytes): string
    {
        return $this->humanBytes($bytes);
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB'];
        $value = (float)$bytes;
        foreach ($units as $unit) {
            if ($value < 1024 || $unit === 'GiB') {
                return $unit === 'B' ? ((string)(int)$value) . 'B' : number_format($value, 1) . $unit;
            }
            $value /= 1024;
        }
        return ((string)$bytes) . 'B';
    }
}
