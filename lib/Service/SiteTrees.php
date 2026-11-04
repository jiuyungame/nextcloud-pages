<?php

declare(strict_types=1);

namespace OCA\Pages\Service;

use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * The two content directories the **bridge** writes, read without going through the filecache.
 *
 * The site folder holds three fixed subdirectories and the app derives every path from `nc_path`; it
 * stores none of them:
 *
 *   `source/`   the site skeleton, edited by the administrator - the app only makes sure it exists
 *   `online/`   the published content: a target is on the site exactly while `online/<target>` is a file
 *   `pending/`  the frozen candidates: a target waits for a decision exactly while `pending/<target>` is one
 *
 * That is the whole state model of this app: the manifest no longer carries a candidate snapshot, a
 * published snapshot or a published list, because the two directories are the record. Reading them is
 * therefore the one place "is it live" and "is it waiting" are answered, and this class is that place
 * - so the reverse index, the review service, the browse tree and the counts cannot disagree.
 *
 * **The bridge is the writer of those two directories, and it does not write through Nextcloud.**
 * `candidate`, `publish`, `discard`, `take-down` and `mirror` land a file with a temporary file and a
 * rename on the bridge's host, so the filecache only learns about it when something scans the folder
 * (`filesystem_check_changes=0`, the default, means "never"). A listing through `IRootFolder` would
 * therefore answer "not live" for a page that was just published and "nothing pending" for a candidate
 * that was just frozen - the decision after a publish would say "nothing is waiting", a take-down
 * "the site does not serve this". So the two maps come from the bridge itself, in **one** read-only,
 * lock-free request ({@see BridgeClient::pathsSnapshot()}, `GET /site/paths`): it walks the same
 * folders on the host that wrote them, and no filecache is involved.
 *
 * The Files API scan is kept as the fallback for a bridge that is down or not configured yet: offline
 * the app then behaves as it did before (a listing that may lag), instead of claiming the site is
 * empty. A bridge failure is logged, never thrown: the callers ask "is this live", and "I cannot tell"
 * must not break a page. The fallback answer is marked untrusted ({@see self::isTrusted()}): read
 * paths keep it, state-changing paths refuse it rather than acting on a listing that may lag.
 *
 * A read is cached for the rest of the request: a page view asks "is this live" for every row of a
 * list, and one bridge request (or one directory walk) per row would be a request per article. A
 * bridge operation that moves bytes (candidate, publish, discard, take-down, mirror) makes the cache
 * stale, which is what {@see self::forget()} is for; a request that does several operations calls it
 * after each one.
 *
 * Both directories are optional: a site that was never published has no `online/`, a site with
 * nothing under review has no `pending/`. A missing one reads as the empty set, never as an error.
 */
final class SiteTrees
{
    /** The skeleton: the one directory of the three the app creates and the administrator edits. */
    public const SOURCE = 'source';

    /** The published content: written by the bridge on `publish` / `take-down`. */
    public const ONLINE = 'online';

    /** The frozen candidates: written by the bridge on `candidate`, removed on `publish` / `discard`. */
    public const PENDING = 'pending';

    /** The three fixed names, in the order the site folder shows them. */
    public const DIRS = [self::SOURCE, self::ONLINE, self::PENDING];

    /** How deep a tree is walked: a site tree is content, not a mount point. */
    private const MAX_DEPTH = 12;

    /** How many entries one scan reads, so a runaway tree cannot hold a request open. */
    private const MAX_ENTRIES = 20000;

    /** @var array{online:array<string,int>,pending:array<string,int>}|null the request's one read */
    private ?array $snapshot = null;

    /**
     * Whether the cached read came from the bridge itself.
     *
     * A bridge that answers is the writer of both directories, so its walk is the truth. The Files API
     * fallback is not: it may lag behind bridge-written files (or miss them entirely), so an empty
     * answer from it does not mean "not live" - only "I could not see the folder". Read-only callers
     * keep using the fallback; the state-changing ones ask {@see self::isTrusted()} and refuse instead
     * of acting on a guess.
     */
    private bool $trusted = false;

    public function __construct(
        private SiteRegistry $registry,
        private FileService $files,
        private BridgeClient $bridge,
        private LoggerInterface $logger,
    ) {
    }

    /** @return array<string,int> */
    public function online(): array
    {
        return $this->snapshot()['online'];
    }

    /** @return array<string,int> */
    public function pending(): array
    {
        return $this->snapshot()['pending'];
    }

    /** Whether the site serves a version of this target: `online/<target>` is a file. */
    public function isOnline(string $target): bool
    {
        $key = self::key($target);

        return $key !== '' && isset($this->online()[$key]);
    }

    /** Whether a candidate for this target waits for a decision: `pending/<target>` is a file. */
    public function isPending(string $target): bool
    {
        $key = self::key($target);

        return $key !== '' && isset($this->pending()[$key]);
    }

    /** The moment `online/<target>` was last written, 0 while the site does not serve it. */
    public function onlineStamp(string $target): int
    {
        $key = self::key($target);

        return $key === '' ? 0 : (int)($this->online()[$key] ?? 0);
    }

    /** The moment `pending/<target>` was frozen, 0 while nothing waits for it. */
    public function pendingStamp(string $target): int
    {
        $key = self::key($target);

        return $key === '' ? 0 : (int)($this->pending()[$key] ?? 0);
    }

    /** @return list<string> the published targets, sorted */
    public function onlineTargets(): array
    {
        $keys = array_keys($this->online());
        sort($keys);

        return $keys;
    }

    /** @return list<string> the targets waiting for a decision, sorted */
    public function pendingTargets(): array
    {
        $keys = array_keys($this->pending());
        sort($keys);

        return $keys;
    }

    /** @return array{pending:int,online:int} */
    public function counts(): array
    {
        return ['pending' => count($this->pending()), 'online' => count($this->online())];
    }

    /**
     * Whether the current snapshot can be trusted: the bridge answered it.
     *
     * `false` means the answer came from the Files API fallback (or from a bridge failure), so
     * "nothing is live / nothing is pending" may be the filecache lagging rather than a fact. A call
     * that only displays the state can keep it; a call that would change something must refuse
     * ({@see \OCA\Pages\Service\ReviewStore::requireTrustedTrees()}).
     *
     * An unconfigured site answers `true`: with no site there is no folder whose content could be
     * missed, so the empty answer is complete (and a fresh install must stay usable).
     */
    public function isTrusted(): bool
    {
        $this->snapshot();

        return $this->trusted;
    }

    /**
     * Drops the cached read: a bridge operation just moved bytes in one of the two directories, and
     * the next question has to be answered by the folders, not by what they held before the call.
     */
    public function forget(): void
    {
        $this->snapshot = null;
        $this->trusted = false;
    }

    /**
     * Both directories in one answer, read once per request.
     *
     * The bridge is asked first because it is the writer: its walk cannot lag behind its own writes,
     * while a Nextcloud listing goes through a filecache that a bridge-written file does not enter on
     * its own. When the bridge cannot answer - not configured yet, unreachable, or an answer this app
     * does not understand - the Files API scan takes over, so an offline app keeps the behaviour it
     * had instead of reporting an empty site. Either way the answer is the empty set at worst, never
     * an exception: every caller is asking a yes/no question about one article. The answer records
     * which of the two produced it, so a caller that would change state can ask
     * {@see self::isTrusted()} first.
     *
     * @return array{online:array<string,int>,pending:array<string,int>}
     */
    private function snapshot(): array
    {
        if ($this->snapshot !== null) {
            return $this->snapshot;
        }
        if ($this->registry->getSite() === null) {
            // no site folder exists, so there is no bridge-written content a listing could miss
            $this->trusted = true;

            return $this->snapshot = ['online' => [], 'pending' => []];
        }
        try {
            $paths = $this->bridge->pathsSnapshot();
            $this->trusted = true;

            return $this->snapshot = $paths;
        } catch (\Throwable $e) {
            if ($e instanceof BridgeException && $e->getBridgeCode() === 'NOT_CONFIGURED') {
                // a site that was never paired asks this on every request: not an error, just the
                // pre-bridge behaviour
                $this->logger->debug('pages: no bridge is configured, reading the site content directories through the Files API', [
                    'exception' => $e,
                ]);
            } else {
                $this->logger->warning('pages: the bridge cannot list the site content directories, reading them through the Files API', [
                    'exception' => $e,
                ]);
            }
        }

        $this->trusted = false;

        return $this->snapshot = [
            'online' => $this->scan(self::ONLINE),
            'pending' => $this->scan(self::PENDING),
        ];
    }

    /**
     * One directory of the site folder, as `target => mtime` - the fallback for a bridge that cannot
     * answer.
     *
     * A site that is not configured, a directory that is not there and a backend that will not answer
     * all read as the empty set: the callers ask "is this live", and "I cannot tell" must not turn
     * into a live page. The failure is logged at debug level, because a site that was never published
     * hits this path on every request and it is not an error.
     *
     * @return array<string,int>
     */
    private function scan(string $name): array
    {
        $site = $this->registry->getSite();
        if ($site === null) {
            return [];
        }
        $uid = trim((string)($site['nc_user'] ?? ''));
        $ncPath = trim((string)($site['nc_path'] ?? ''));
        if ($uid === '' || $ncPath === '') {
            return [];
        }
        try {
            $folder = $this->files->existingFolder($uid, rtrim($ncPath, '/') . '/' . $name);
        } catch (\Throwable $e) {
            $this->logger->debug('pages: the site directory could not be read', [
                'user' => $uid,
                'path' => $ncPath . '/' . $name,
                'exception' => $e,
            ]);

            return [];
        }
        if ($folder === null) {
            return [];
        }

        $out = [];
        $walk = function (Folder $directory, string $prefix, int $depth) use (&$walk, &$out): void {
            if ($depth > self::MAX_DEPTH || count($out) >= self::MAX_ENTRIES) {
                return;
            }
            try {
                $children = $directory->getDirectoryListing();
            } catch (NotFoundException) {
                // it vanished between the listing and the read: nothing of it is there to report
                return;
            } catch (\Throwable $e) {
                $this->logger->debug('pages: a site directory could not be listed', [
                    'path' => $directory->getPath(),
                    'exception' => $e,
                ]);

                return;
            }
            foreach ($children as $child) {
                if (count($out) >= self::MAX_ENTRIES) {
                    return;
                }
                $name = $child->getName();
                if ($name === '' || $name === '.' || $name === '..' || str_contains($name, '/')) {
                    continue;
                }
                $rel = $prefix === '' ? $name : $prefix . '/' . $name;
                if ($child instanceof Folder) {
                    $walk($child, $rel, $depth + 1);
                    continue;
                }
                $out[$rel] = (int)$child->getMTime();
            }
        };
        $walk($folder, '', 1);

        return $out;
    }

    /** One target as the manifest keys it: site-relative, forward slashes, no leading slash. */
    private static function key(string $target): string
    {
        return ltrim(trim(str_replace('\\', '/', $target)), '/');
    }
}
