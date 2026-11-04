<?php

declare(strict_types=1);

namespace OCA\Pages\Service;

use OCP\IL10N;

/**
 * One archive of site source, read in memory and validated before anything is written.
 *
 * The two ways content can enter the Nextcloud folder from outside - the tarball **Import** and the
 * git **Pull** - both end in a complete replacement, so both go through this one reader. It answers
 * a flat map of `path => bytes` and never touches the filesystem of the instance: the caller writes
 * those bytes through the Nextcloud file API, which is the only writer the file cache sees.
 *
 * The rules are the same for both entry points, and they are deliberately strict:
 *
 *  - a size limit per entry and in total (a decompression bomb must fail, not fill the data disk);
 *  - zip-slip protection: no absolute path, no `..`, no NUL, and backslashes are normalised to `/`
 *    before the traversal check so a Windows-spelled escape cannot slip through;
 *  - generated directories (`_site/`, `.git/`, `vendor/`, `__pycache__/`) are dropped, not imported:
 *    they are build output, history or dependencies, and the bridge regenerates or owns them;
 *  - filenames must be valid UTF-8 and survive unchanged (a Chinese page name is a page name).
 */
class SourceArchive
{
    /** Largest single file the reader accepts, in bytes. */
    public const MAX_ENTRY_BYTES = 32 * 1024 * 1024;

    /** Largest total the reader accepts, in bytes; also the upload limit the controller checks. */
    public const MAX_TOTAL_BYTES = 128 * 1024 * 1024;

    /** Most entries the reader accepts (a site is source, not a dump of the internet). */
    public const MAX_ENTRIES = 20000;

    /** Deepest path accepted; the tree API caps at a similar depth. */
    public const MAX_DEPTH = 20;

    /**
     * Directory names that are never source. They are dropped with a count instead of failing the
     * import, because their presence in a tarball is normal (a `git archive` of a built site, a
     * vendor bundle) and the replacement is still exactly what the administrator meant to import.
     */
    private const DROPPED_DIRS = ['_site', '.git', 'vendor', '__pycache__'];

    public function __construct(private IL10N $l10n)
    {
    }

    /**
     * Read one uploaded archive (zip or tar.gz) into a complete source tree.
     *
     * @param string $bytes    the archive as uploaded
     * @param string $filename the client's file name, used only to pick the reader
     * @param bool   $requireConfig whether a `_config.yml` has to be present (Import: yes; Pull: no,
     *                              the repository is whatever the administrator pushed there)
     * @return array{files:array<string,string>,skipped:int,dropped:list<string>,config:string}
     * @throws \InvalidArgumentException with a message fit for the administrator
     */
    public function read(string $bytes, string $filename = '', bool $requireConfig = true): array
    {
        if ($bytes === '') {
            throw new \InvalidArgumentException($this->l10n->t('The archive is empty'));
        }
        if (strlen($bytes) > self::MAX_TOTAL_BYTES) {
            throw new \InvalidArgumentException($this->l10n->t(
                'The archive is too large (%s); the limit is %s.',
                [$this->bytesLabel(strlen($bytes)), $this->bytesLabel(self::MAX_TOTAL_BYTES)],
            ));
        }

        $entries = $this->isZip($bytes, $filename) ? $this->readZip($bytes) : $this->readTarGz($bytes);

        $files = [];
        $skipped = 0;
        $dropped = [];
        $total = 0;
        foreach ($entries as $path => $content) {
            $clean = $this->cleanPath($path);
            if ($clean === null) {
                throw new \InvalidArgumentException($this->l10n->t(
                    'The archive contains an unsafe path (%s); it was not imported.',
                    [$path],
                ));
            }
            if ($clean === '') {
                $skipped++;
                continue;
            }
            if ($this->isDropped($clean)) {
                $skipped++;
                foreach (explode('/', $clean) as $segment) {
                    if (in_array($segment, self::DROPPED_DIRS, true)) {
                        $dropped[$segment] = true;
                        break;
                    }
                }
                continue;
            }
            if (!mb_check_encoding($clean, 'UTF-8')) {
                throw new \InvalidArgumentException($this->l10n->t(
                    'A filename in the archive is not valid UTF-8 (%s); re-create the archive with UTF-8 names.',
                    [bin2hex($clean)],
                ));
            }
            if (strlen($content) > self::MAX_ENTRY_BYTES) {
                throw new \InvalidArgumentException($this->l10n->t(
                    'A file in the archive is too large (%1$s: %2$s).',
                    [$clean, $this->bytesLabel(strlen($content))],
                ));
            }
            $total += strlen($content);
            if ($total > self::MAX_TOTAL_BYTES) {
                throw new \InvalidArgumentException($this->l10n->t(
                    'The archive is too large when unpacked; the limit is %s.',
                    [$this->bytesLabel(self::MAX_TOTAL_BYTES)],
                ));
            }
            if (count($files) >= self::MAX_ENTRIES) {
                throw new \InvalidArgumentException($this->l10n->t(
                    'The archive holds more than %s files.',
                    [(string)self::MAX_ENTRIES],
                ));
            }
            $files[$clean] = $content;
        }

        $files = $this->stripSingleTopDir($files);
        $config = $this->findConfig($files);
        if ($requireConfig && $config === '') {
            throw new \InvalidArgumentException($this->l10n->t(
                'The archive has no _config.yml at its top level (or directly inside its single top-level folder), so it is not a site source.',
            ));
        }

        return [
            'files' => $files,
            'skipped' => $skipped,
            'dropped' => array_keys($dropped),
            'config' => $config,
        ];
    }

    /** Whether the bytes look like a zip (PK\x03\x04), falling back to the extension. */
    private function isZip(string $bytes, string $filename): bool
    {
        if (str_starts_with($bytes, "PK\x03\x04") || str_starts_with($bytes, "PK\x05\x06")) {
            return true;
        }
        if (str_starts_with($bytes, "\x1f\x8b")) {
            return false;
        }
        $lower = strtolower($filename);

        return str_ends_with($lower, '.zip');
    }

    /**
     * @return array<string,string> path => bytes (directory entries are left out)
     * @throws \InvalidArgumentException when the zip cannot be read
     */
    private function readZip(string $bytes): array
    {
        $temp = tempnam(sys_get_temp_dir(), 'pages-import-');
        if ($temp === false) {
            throw new \RuntimeException($this->l10n->t('A temporary file could not be created'));
        }
        try {
            if (file_put_contents($temp, $bytes) === false) {
                throw new \RuntimeException($this->l10n->t('The temporary file could not be written'));
            }
            $zip = new \ZipArchive();
            if ($zip->open($temp) !== true) {
                throw new \InvalidArgumentException($this->l10n->t('The archive is not a readable zip file'));
            }
            $out = [];
            try {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $stat = $zip->statIndex($i);
                    if ($stat === false) {
                        continue;
                    }
                    $name = (string)($stat['name'] ?? '');
                    if ($name === '' || str_ends_with($name, '/')) {
                        continue;   // a directory entry
                    }
                    if ((int)$stat['size'] > self::MAX_ENTRY_BYTES) {
                        throw new \InvalidArgumentException($this->l10n->t(
                            'A file in the archive is too large (%1$s: %2$s).',
                            [$name, $this->bytesLabel((int)$stat['size'])],
                        ));
                    }
                    $content = $zip->getFromIndex($i);
                    if ($content === false) {
                        throw new \InvalidArgumentException($this->l10n->t(
                            'A file in the archive could not be read (%s).',
                            [$name],
                        ));
                    }
                    $out[$name] = $content;
                }
            } finally {
                $zip->close();
            }

            return $out;
        } finally {
            @unlink($temp);
        }
    }

    /**
     * Read a tar.gz into memory with a small tar reader of its own.
     *
     * PHP's `PharData` was the obvious tool and turned out to be the wrong one: it exposes entry names
     * through a filesystem-like API that mangles anything outside the C locale, so a Chinese filename
     * arrived as `??` and could not be read at all. A site may well name its pages in Chinese, so the
     * headers are parsed here instead - 512-byte blocks, the GNU long-name entry and the pax `path`
     * record included - and the names stay exactly the bytes the archive carries.
     *
     * @return array<string,string> path => bytes
     * @throws \InvalidArgumentException when the tarball cannot be read
     */
    private function readTarGz(string $bytes): array
    {
        if (!str_starts_with($bytes, "\x1f\x8b")) {
            throw new \InvalidArgumentException($this->l10n->t(
                'The archive is neither a zip nor a tar.gz file.',
            ));
        }
        $raw = @gzdecode($bytes);
        if (!is_string($raw) || $raw === '') {
            throw new \InvalidArgumentException($this->l10n->t('The archive is not a readable tar.gz file'));
        }

        $out = [];
        $offset = 0;
        $length = strlen($raw);
        $count = 0;
        $longName = null;
        $paxPath = null;
        while ($offset + 512 <= $length) {
            $header = substr($raw, $offset, 512);
            if (trim($header, "\0") === '') {
                break;   // two zero blocks end the archive; one is enough to stop
            }
            if (!$this->checksumOk($header)) {
                throw new \InvalidArgumentException($this->l10n->t('The tar header of the archive is corrupt'));
            }
            $name = rtrim(substr($header, 0, 100), "\0");
            $prefix = rtrim(substr($header, 345, 155), "\0");
            $type = substr($header, 156, 1);
            $size = $this->octal(substr($header, 124, 12));
            $offset += 512;
            if ($size < 0 || $size > $length - $offset) {
                throw new \InvalidArgumentException($this->l10n->t('The tar entry %s is truncated', [$name]));
            }
            $data = $size > 0 ? substr($raw, $offset, $size) : '';
            $offset += (int)(ceil($size / 512) * 512);

            if ($type === 'L') {
                $longName = rtrim($data, "\0");

                continue;
            }
            if ($type === 'x') {
                $paxPath = $this->paxPath($data) ?? $paxPath;

                continue;
            }
            if ($type === 'g' || $type === 'K') {
                continue;   // pax global header / long link name: no content of its own
            }
            if ($type !== '0' && $type !== "\0" && $type !== '7') {
                continue;   // directories, symlinks, devices: nothing to write into the folder
            }

            $path = $longName ?? $paxPath ?? ($prefix === '' ? $name : $prefix . '/' . $name);
            $longName = null;
            $paxPath = null;
            if ($size > self::MAX_ENTRY_BYTES) {
                throw new \InvalidArgumentException($this->l10n->t(
                    'A file in the archive is too large (%1$s: %2$s).',
                    [$path, $this->bytesLabel($size)],
                ));
            }
            if (++$count > self::MAX_ENTRIES) {
                throw new \InvalidArgumentException($this->l10n->t(
                    'The archive holds more than %s files.',
                    [(string)self::MAX_ENTRIES],
                ));
            }
            $out[$path] = $data;
        }

        return $out;
    }

    /** The tar header checksum: the header's bytes with the checksum field read as spaces. */
    private function checksumOk(string $header): bool
    {
        $expected = $this->octal(substr($header, 148, 8));
        if ($expected === 0) {
            return false;
        }
        $calculated = 0;
        $blanked = substr_replace($header, '        ', 148, 8);
        for ($i = 0; $i < 512; $i++) {
            $calculated += ord($blanked[$i]);
        }

        return $calculated === $expected;
    }

    private function octal(string $field): int
    {
        $text = trim(str_replace("\0", ' ', $field));
        $text = trim($text);
        if ($text === '') {
            return 0;
        }

        return (int)octdec($text);
    }

    /** The `path=` record of a pax extended header, or null when it carries none. */
    private function paxPath(string $data): ?string
    {
        $path = null;
        $pos = 0;
        $length = strlen($data);
        while ($pos < $length) {
            $space = strpos($data, ' ', $pos);
            if ($space === false) {
                break;
            }
            $recordLength = (int)substr($data, $pos, $space - $pos);
            if ($recordLength <= 0 || $pos + $recordLength > $length) {
                break;
            }
            $record = substr($data, $pos, $recordLength);
            $equals = strpos($record, '=');
            if ($equals !== false) {
                $key = substr($record, $space - $pos + 1, $equals - ($space - $pos) - 1);
                if ($key === 'path') {
                    $path = rtrim(substr($record, $equals + 1), "\n");
                }
            }
            $pos += $recordLength;
        }

        return $path;
    }

    /**
     * Normalise one entry path, or null when it must be refused.
     *
     * `git archive` and the tar readers may prefix every entry with `./`; that is stripped. A path
     * that escapes the archive root in any spelling is a zip-slip attempt and is refused whole.
     */
    private function cleanPath(string $path): ?string
    {
        $path = str_replace('\\', '/', $path);
        if (str_contains($path, "\0")) {
            return null;
        }
        // an absolute path or a drive letter is never a site-relative path
        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path) === 1) {
            return null;
        }
        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                return null;
            }
            $parts[] = $segment;
        }
        if ($parts === []) {
            return '';
        }
        if (count($parts) > self::MAX_DEPTH) {
            return null;
        }

        return implode('/', $parts);
    }

    /** Whether a path sits inside a directory that is never source. */
    private function isDropped(string $path): bool
    {
        foreach (explode('/', $path) as $segment) {
            if (in_array($segment, self::DROPPED_DIRS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Strip one wrapper directory when the archive has exactly one at its top level.
     *
     * A `git archive` or a GitHub tarball wraps the tree in `<repo>-<ref>/`; importing that would put
     * the site one level too deep, and the site folder would hold a single folder instead of the site.
     *
     * @param array<string,string> $files
     * @return array<string,string>
     */
    private function stripSingleTopDir(array $files): array
    {
        if ($files === []) {
            return $files;
        }
        $tops = [];
        foreach (array_keys($files) as $path) {
            $tops[explode('/', $path)[0]] = true;
        }
        if (count($tops) !== 1) {
            return $files;
        }
        $top = (string)array_key_first($tops);
        // only a real directory can be stripped: a single file at the root is not a wrapper
        foreach (array_keys($files) as $path) {
            if (!str_starts_with($path, $top . '/')) {
                return $files;
            }
        }
        $out = [];
        foreach ($files as $path => $content) {
            $out[substr($path, strlen($top) + 1)] = $content;
        }

        return $out;
    }

    /**
     * The `_config.yml` the site is built from: top level, or directly inside the single top-level
     * folder (which `stripSingleTopDir()` has already removed). Returns '' when there is none.
     *
     * @param array<string,string> $files
     */
    private function findConfig(array $files): string
    {
        return isset($files['_config.yml']) ? '_config.yml' : '';
    }

    private function bytesLabel(int $bytes): string
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
