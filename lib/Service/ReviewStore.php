<?php

declare(strict_types=1);

namespace OCA\Pages\Service;

use OCA\Pages\Db\Article;
use OCA\Pages\Db\ArticleMapper;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IConfig;
use OCP\IL10N;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * The review manifest: who asked for what, and who decided it.
 *
 * It is a file and not a table because the decisions have to survive a rebuild of the app's database
 * and be restorable by hand. It lives in the app's own storage under Nextcloud's appdata
 * (`appdata_<instanceid>/pages/review.json`), which is private to the server: invisible in the Files
 * app, never served over HTTP, not in anybody's file tree. That is the point - the record of who
 * decided what about whose article must not be a file the author of that article can edit, and the
 * site storage user can be an author.
 *
 * **The manifest no longer records what the site has.** The three content directories the bridge
 * maintains are the record:
 *
 *   `online/<target>` exists  →  the site serves that article            (state `external`)
 *   `pending/<target>` exists →  a candidate waits for a decision        (state `requested`)
 *   neither, no down request  →  the article is internal
 *
 * so there is no `candidate` snapshot, no `published` snapshot and no `published` list any more:
 * `pending/` and `online/` are the truth, and duplicating them here was the source of every drift the
 * old model had to reconcile. What the manifest still carries is the one thing the folders cannot:
 * an author's **request to take an article off the site**, and the reviewer's note.
 *
 * ```json
 * {
 *   "version": 4,
 *   "entries": {
 *     "tmp/alice/2026-05-01-hello.md": {
 *       "owner": "alice",
 *       "candidateDigest": "9f2c…",
 *       "downRequest": { "at": 1792041500, "sourceDeleted": false },
 *       "decidedBy": "bob", "note": ""
 *     },
 *     "tmp/alice/2026-05-02-gone.md": {
 *       "owner": "alice",
 *       "candidateDigest": "",
 *       "downRequest": { "at": 1792042500, "sourceDeleted": true },
 *       "decidedBy": "", "note": ""
 *     }
 *   }
 * }
 * ```
 *
 * `candidateDigest` is the sha256 of the frozen `pending/<target>` bytes the author asked about,
 * as the bridge's `candidate` op answered it. It is the one fact about a candidate the folders
 * cannot carry: `pending/` holds *some* bytes, but only the digest says they are the bytes the
 * reviewer approved. An approval sends it back to the bridge, which refuses to publish a file
 * whose hash does not match - the author may have replaced the candidate after the request. It is
 * empty while no candidate of this entry is bound to a request.
 *
 * Keys are site-relative paths without a leading slash. An entry exists only while there is something
 * to record about the article: a bound candidate, a down request, a reviewer's trail, or the owner of
 * an article whose candidate is waiting. An article the manifest does not mention is `internal` unless
 * `online/` names it. `state` is deliberately **not stored**: the folders decide, and a stored copy of
 * a derived fact is exactly what used to go stale.
 *
 * The database is its cache: `pages_articles` is written from the manifest after every change (see
 * {@see self::syncCaches()}), which keeps the queue and the counts plain queries while every decision
 * asks the folders. That sync is best effort on purpose: a failure leaves the decision in place and is
 * reported ({@see self::lastCacheSyncFailed()}), because the projection is repaired by the next write
 * while a write reported as failed cannot be retried at all.
 *
 * The older fields are gone. Version 3's `candidate` / `published` snapshots, its `state` column,
 * version 2's `digest` and version 1's `intent` / `submittedAt` / `publishedAt` were all copies of
 * facts the folders hold, and {@see self::decode()} reads only `version` and `entries`: a version-1
 * document, whose decisions sat under `articles`, reads as the empty manifest, and nothing migrates
 * it. The next write stores the version-4 shape.
 *
 * A missing or empty file reads as the empty manifest, which is what a site nobody has reviewed
 * anything on yet looks like. A file that is there and is **not JSON** is a different thing: it reads
 * as the empty manifest for every reader (nothing in it can be trusted), but it is not empty - the
 * bytes may hold every decision - so {@see self::isCorrupt()} says which of the two an answer came
 * from, {@see self::mutate()} refuses to write over it, and the bytes are copied aside
 * (`manifest.corrupt-<ts>.json` next to it) before the refusal.
 */
final class ReviewStore
{
    /** The manifest's name inside the app's own appdata folder. */
    public const FILE = 'review.json';

    /** The prefix of the copies taken aside when the manifest cannot be parsed. */
    public const CORRUPT_PREFIX = 'manifest.corrupt-';

    /**
     * Shape of the document: 4 is the directory-derived model. An entry carries `owner`,
     * `candidateDigest`, `downRequest`, `decidedBy` and `note` and nothing else; whether the article
     * is on the site and whether a candidate waits is answered by `online/` and `pending/`
     * ({@see SiteTrees}).
     */
    public const VERSION = 4;

    /** How often a write is retried while somebody else keeps changing the manifest. */
    private const WRITE_ATTEMPTS = 3;

    /** The lock every writer takes for the whole read-modify-write cycle. */
    private const LOCK_KEY = 'pages/review.json';

    /** The reason the stored document last read was there but not parsable, '' while it was fine. */
    private string $corruption = '';

    /** Why the database caches could not follow the last write, '' while they are in step. */
    private string $cacheSyncError = '';

    /** Whether that corruption was already logged in this request. */
    private bool $corruptionReported = false;

    public function __construct(
        private IAppData $appData,
        private ILockingProvider $locking,
        private SiteRegistry $registry,
        private SiteTrees $trees,
        private IConfig $config,
        private ArticleMapper $articles,
        /**
         * The one call this store makes on the bridge: withdrawing a candidate whose source is gone
         * before a take-off request may be recorded next to it
         * ({@see self::requestTakeDownForDeletedSources()}).
         */
        private BridgeClient $bridge,
        private LoggerInterface $logger,
        private IL10N $l10n,
    ) {
    }

    /**
     * The whole manifest. Never throws: a file that is missing, empty or unparsable reads as the empty
     * manifest, so no page breaks over a bookkeeping file that is not there yet.
     *
     * "Unparsable" is not "empty" though: {@see self::isCorrupt()} says which of the two this answer
     * came from.
     *
     * @return array{version:int,entries:array<string,array<string,mixed>>}
     */
    public function read(): array
    {
        return self::decode($this->load()['json']) ?? self::empty();
    }

    /** Whether the manifest file was there but is not parsable JSON. */
    public function isCorrupt(): bool
    {
        return $this->corruption !== '';
    }

    /**
     * Why the article caches could not follow the last write of this request, or null while they did.
     *
     * The manifest is written first, while `pages_articles` is only its projection: a failing sync
     * does not throw (the decision **is** recorded, and retrying would answer "nothing is pending"),
     * it is reported here so the answer can say "recorded, the queue is refreshing".
     */
    public function lastCacheSyncFailed(): ?string
    {
        return $this->cacheSyncError === '' ? null : $this->cacheSyncError;
    }

    /**
     * The state of one article. It is derived, never stored: a down request means something waits for
     * a decision, otherwise the site has the article exactly while `online/` holds it.
     */
    public function state(string $path): string
    {
        return $this->stateIn($this->read(), $path);
    }

    /**
     * The same answer for a caller that already read the manifest once (a scan asks it per article).
     *
     * @param array<string,mixed> $manifest as read() or mutate() returned it
     */
    public function stateIn(array $manifest, string $path): string
    {
        if (self::downRequestOf($manifest, $path) !== null) {
            return Article::STATE_REQUESTED;
        }

        return $this->isLive($path) ? Article::STATE_EXTERNAL : Article::STATE_INTERNAL;
    }

    /**
     * The direction of the pending request on an article: `online` while a candidate waits in
     * `pending/`, `offline` while the author asked for a take-down, '' while nothing is pending.
     *
     * @param array<string,mixed> $manifest as read() or mutate() returned it
     */
    public function intentIn(array $manifest, string $path): string
    {
        if ($this->isPending($path)) {
            return Article::INTENT_ONLINE;
        }

        return self::downRequestOf($manifest, $path) !== null ? Article::INTENT_OFFLINE : '';
    }

    /**
     * Whether something waits for a decision on an article: a candidate in `pending/` or an author's
     * take-down request.
     *
     * @param array<string,mixed> $manifest as read() or mutate() returned it
     */
    public function hasPendingIn(array $manifest, string $path): bool
    {
        return $this->isPending($path) || self::downRequestOf($manifest, $path) !== null;
    }

    /** Whether the site serves this article: `online/<target>` exists (the bridge writes it). */
    public function isLive(string $path): bool
    {
        return $this->trees->isOnline($path);
    }

    /** Whether a candidate for this article waits for a decision: `pending/<target>` exists. */
    public function isPending(string $path): bool
    {
        return $this->trees->isPending($path);
    }

    /** When the candidate waiting for this article was frozen, 0 while nothing waits. */
    public function pendingStamp(string $path): int
    {
        return $this->trees->pendingStamp($path);
    }

    /** When the site's version of this article was last written, 0 while it has none. */
    public function liveStamp(string $path): int
    {
        return $this->trees->onlineStamp($path);
    }

    /** @return list<string> the targets waiting for a decision, from `pending/` */
    public function pendingTargets(): array
    {
        return $this->trees->pendingTargets();
    }

    /** @return list<string> the targets the site serves, from `online/` */
    public function onlineTargets(): array
    {
        return $this->trees->onlineTargets();
    }

    /** @return array{pending:int,online:int} */
    public function counts(): array
    {
        return $this->trees->counts();
    }

    /**
     * Whether the content directories behind this answer were read from the bridge itself.
     *
     * The Files API fallback is good enough to display a state, but it is not evidence that nothing is
     * live: a bridge-written file may simply not be in the filecache yet
     * ({@see SiteTrees::isTrusted()}). Only the callers that change state have to care.
     */
    public function treesTrusted(): bool
    {
        return $this->trees->isTrusted();
    }

    /**
     * Refuse a state-changing action while the bridge cannot confirm the content directories.
     *
     * Read-only questions keep the fallback answer; an action that would publish, discard or forget
     * something refuses instead of acting on a "not live / nothing pending" that the filecache may
     * have invented. The caller retries once the bridge answers.
     *
     * @throws ConflictException with `reason: BRIDGE_UNKNOWN`
     */
    public function requireTrustedTrees(): void
    {
        if ($this->trees->isTrusted()) {
            return;
        }
        throw new ConflictException(
            $this->l10n->t('The site content could not be confirmed because the bridge did not answer; please try again later.'),
            ['reason' => 'BRIDGE_UNKNOWN'],
        );
    }

    /**
     * Drops the cached directory scans: a bridge operation just moved bytes in `online/` or `pending/`
     * and the next question must be answered by the folder, not by what it held before the call.
     */
    public function refreshTrees(): void
    {
        $this->trees->forget();
    }

    /**
     * The entry of one article, or [] while the manifest does not know it (which reads as "internal,
     * nothing requested").
     *
     * @return array<string,mixed>
     */
    public function entry(string $path): array
    {
        return self::entryOf($this->read(), $path);
    }

    /**
     * The manifest as JSON, in the bytes the app writes: what a backup has to copy. A file that is not a
     * usable manifest reads as the empty one, exactly like `read()` does.
     */
    public function json(): string
    {
        return $this->encode($this->read());
    }

    /**
     * The document exactly as the file holds it, without the normalising read: the reset backup copies
     * these bytes so a restore is a file copy.
     */
    public function rawJson(): string
    {
        return $this->load()['json'];
    }

    /** Whether the manifest file is there at all; the doctor warns while it is not. */
    public function exists(): bool
    {
        return $this->currentFile() !== null;
    }

    /** Where the manifest lives, as an administrator recognises it: `appdata_<instanceid>/pages/review.json`. */
    public function location(): string
    {
        return 'appdata_' . $this->config->getSystemValueString('instanceid')
            . '/' . $this->folder()->getName() . '/' . self::FILE;
    }

    /**
     * The one write path: read the manifest, apply `$change`, write the result back, then sync the
     * article cache from it. The whole cycle runs under an exclusive lock, so two review actions cannot
     * interleave read-apply-write and lose each other's entry.
     *
     * The lock orders the writers that go through this app, while the content and ETag comparison in
     * {@see self::write()} catches a file that was replaced behind its back - a restored backup, a copy
     * an administrator put there, a second installation sharing the storage. On such a change the
     * document is re-read and `$change` is applied to the fresh one, up to self::WRITE_ATTEMPTS.
     *
     * @param callable(array<string,mixed>):array<string,mixed> $change
     * @return array<string,mixed> the manifest as it was written
     * @throws NotPermittedException when the manifest exists but cannot be read, or is not parsable JSON
     * @throws ConflictException when another writer holds the lock, or kept replacing the file
     */
    public function mutate(callable $change): array
    {
        $this->acquire();
        try {
            for ($attempt = 1; $attempt <= self::WRITE_ATTEMPTS; $attempt++) {
                $loaded = $this->load();
                if ($loaded['error'] !== '') {
                    if ($loaded['corrupt']) {
                        $this->preserveCorrupt($loaded['json']);
                    }
                    throw new NotPermittedException(
                        $this->l10n->t('The review manifest could not be read: %s', [$loaded['error']]),
                    );
                }

                $updated = self::stamped($change(self::decode($loaded['json']) ?? self::empty()));
                if (!$this->write($updated, $loaded)) {
                    continue;
                }

                try {
                    $this->syncCaches($updated);
                } catch (\Throwable $e) {
                    $this->cacheSyncError = $e->getMessage();
                    $this->logger->error(
                        'pages: the review manifest was written, but the article cache could not be updated',
                        ['exception' => $e],
                    );

                    return $updated;
                }
                $this->cacheSyncError = '';

                return $updated;
            }

            throw new ConflictException($this->l10n->t(
                'The review manifest was changed by somebody else %d times in a row; nothing was recorded.',
                [self::WRITE_ATTEMPTS],
            ));
        } finally {
            $this->release();
        }
    }

    /**
     * Drop the whole manifest: every decision. Only the full reset calls this.
     *
     * The sync that follows cannot empty `pages_articles` by itself, so the article cache is cleared
     * here explicitly: after this call the manifest and the cache table both hold nothing.
     */
    public function clear(): void
    {
        $this->mutate(static fn (array $manifest): array => self::empty());
        $this->articles->deleteAll();
    }

    /**
     * Write fields onto one or more entries, in one write: a decision about a queue is one fact, and
     * writing the manifest per article would mean a whole read-modify-write cycle per article.
     *
     * The fields are the entry's own vocabulary - `owner`, `candidateDigest`, `downRequest`,
     * `decidedBy`, `note`. A key that is present replaces the entry's value (a `null` clears the
     * request, which is how a take-down request is withdrawn), a key that is absent keeps what the
     * entry has. The state is *not* a field a caller writes: it is derived from the folders
     * ({@see self::stateIn()}).
     *
     * @param list<string> $paths
     * @param array<string,mixed> $fields only the keys the caller names are written
     */
    public function setFields(array $paths, array $fields): void
    {
        $keys = self::keys($paths);
        if ($keys === []) {
            return;
        }
        $this->mutate(static fn (array $manifest): array => self::withFields($manifest, $keys, $fields));
    }

    /**
     * Re-keys the manifest when the administrator moves the author content directory: a key the site
     * approved under the old directory names the same file under the new one, so the decision has to
     * follow it - otherwise every approved file would read as a draft again and the mirror would drop
     * it. The bridge's own author-root move drops the old targets and treats the previous directory as
     * a purge root, so no app-side target list has to carry the old paths.
     *
     * When the new key already exists, the entry encountered later keeps its record and is annotated:
     * two records of one path cannot both be true.
     */
    public function rekeyAuthorRoot(string $oldRoot, string $newRoot): void
    {
        $old = trim($oldRoot, '/');
        $new = trim($newRoot, '/');
        if ($old === '' || $new === '' || $old === $new) {
            return;
        }
        $note = $this->l10n->t('Merged into the entry that already existed at this path when the author content directory changed.');
        $this->mutate(static function (array $manifest) use ($old, $new, $note): array {
            $entries = [];
            foreach (($manifest['entries'] ?? []) as $path => $entry) {
                $path = (string)$path;
                $mapped = str_starts_with($path, $old . '/') ? $new . substr($path, strlen($old)) : $path;
                if (isset($entries[$mapped])) {
                    $entry = [...$entry, 'note' => $note];
                }
                $entries[$mapped] = $entry;
            }
            $manifest['entries'] = $entries;

            return $manifest;
        });
        $this->logger->info('pages: the review manifest was re-keyed for a new author content directory', [
            'from' => $old,
            'to' => $new,
        ]);
    }

    /**
     * Forget articles: the file is gone, so nothing about them is left to decide.
     *
     * The cache row goes with the entry: it describes an entry that no longer exists, and no later
     * scan would repair it, because the file it caches is gone too.
     *
     * @param list<string> $paths
     * @return list<string> the paths that had an entry
     */
    public function forgetArticle(array $paths): array
    {
        $keys = self::keys($paths);
        if ($keys === []) {
            return [];
        }

        $removed = [];
        $this->mutate(static function (array $manifest) use ($keys, &$removed): array {
            $removed = [];
            foreach ($keys as $key) {
                if (!isset($manifest['entries'][$key])) {
                    continue;
                }
                unset($manifest['entries'][$key]);
                $removed[] = $key;
            }

            return $manifest;
        });

        $this->dropArticleRows($removed);

        return $removed;
    }

    /**
     * The bookkeeping of a source that is gone: a take-down request for every target the site still
     * serves, plain oblivion for every target it does not.
     *
     * This is the one rule of both delete paths - the app's own delete and the file event of a WebDAV,
     * Files app or sync-client delete - and of a rename, whose old address is exactly a source that is
     * gone ({@see AuthorDeltaService::renamed()}). The file is already gone when this runs, and a
     * `downRequest` is the only thing that ever takes a page off the site: the bridge's `remove` delta
     * drops the **preview** copy, and the site's copy in `online/` is cleared by a take-down alone. An
     * entry left behind without a request would keep a page online that nobody can maintain - and,
     * once the entry is gone as well, nothing can be decided about it any more. With `sourceDeleted` in
     * the request, a reviewer's refusal is the handover to the orphan owner instead of "keep the page
     * up".
     *
     * **The targets are the union of the three facts, not just the manifest keys.** A target can be
     * served (`online/`) or waiting (`pending/`) while the manifest has no entry for it at all: the
     * candidate request writes `pending/` before the entry, and a bridge operation may have published
     * without the app writing one. Reading only `entries` used to miss exactly those pages, so a page
     * the site served kept being served after its source was deleted, with nothing left to decide
     * about. The union is what makes the bookkeeping see the whole state; the caller's `$sources` then
     * select the subtree, exactly as `keysUnder()` did for entries.
     *
     * A target the site does not serve (`online/` has nothing for it) and that has no candidate has
     * nothing left to decide about: it goes, with its cache row. Only a target the manifest really has
     * an entry for is counted in `forgotten`; one that only `pending/` knew has no entry to delete (its
     * cache row still goes), and counting it would tell the caller that more entries were removed than
     * there were.
     *
     * One write for the whole subtree: the requests are one fact ("the author deleted these files").
     *
     * **A target that still has a candidate in `pending/` is the one shape this bookkeeping must not
     * write over** - served or not. The delete path withdraws a candidate on a best-effort basis
     * ({@see AuthorDeltaService::withdrawPending()}), so a `discard` that failed leaves the frozen
     * copy behind. Recording a `downRequest` next to it would be a state no decision can answer:
     * `pending/` wins in {@see ArticleReviewService::decide()}, so an approval would publish the
     * bytes of a file that is gone, while the take-off request stays in the queue for ever. The
     * candidate is therefore withdrawn first, and a discard that fails leaves the entry untouched: no
     * request is recorded, the target is not forgotten, it is reported in `failed`, and the caller may
     * retry.
     *
     * **The bridge is never called while the manifest lock is held.** The directories are read and
     * the candidates are discarded before {@see self::mutate()} takes its lock, and the write itself
     * only removes entries and records requests; a slow bridge therefore never blocks every other
     * manifest writer, and the lock is not held across an HTTP call.
     *
     * @param list<string> $sources the deleted files and/or folders, as site targets
     * @param int $at the moment the requests are filed with, the same one for the whole call
     * @return array{requested:list<string>,forgotten:list<string>,failed:list<string>}
     */
    public function requestTakeDownForDeletedSources(array $sources, int $at): array
    {
        $keys = [];
        foreach (self::keys($sources) as $key) {
            $clean = rtrim($key, '/');
            if ($clean !== '') {
                $keys[$clean] = $clean;
            }
        }
        if ($keys === []) {
            return ['requested' => [], 'forgotten' => [], 'failed' => []];
        }
        $keys = array_values($keys);
        // the manifest is read first, then the two content directories are added to it: the union is
        // the complete record, and `entries` alone no longer decides who is in scope
        $manifest = $this->read();
        $pendingTargets = $this->pendingTargets();
        $onlineTargets = $this->onlineTargets();
        $targets = self::targetsUnder(array_merge(
            array_keys($manifest['entries']),
            $pendingTargets,
            $onlineTargets,
        ), $keys);
        if ($targets === []) {
            return ['requested' => [], 'forgotten' => [], 'failed' => []];
        }

        // Everything the bridge may have to answer is settled here, outside the manifest lock: one
        // read of each directory for the whole subtree, one discard per waiting candidate. The lists
        // are the plan the write below applies; a failure keeps its target out of both lists, so the
        // entry (and the candidate) is left exactly as it was.
        $requested = [];
        $forgotten = [];
        // targets the folders knew but the manifest never did: there is no entry to forget, but a cache
        // row from an earlier scan may still be there and goes with the source
        $dropped = [];
        $failed = [];
        $pending = array_fill_keys($pendingTargets, true);
        $online = array_fill_keys($onlineTargets, true);
        foreach ($targets as $path) {
            if (isset($pending[$path])) {
                if (!$this->discardPendingCandidate(
                    $path,
                    (string)($manifest['entries'][$path]['owner'] ?? ''),
                )) {
                    // the frozen copy is still there, so a take-off request recorded now would
                    // make `pending/` and the request disagree about what is being decided. The
                    // entry (if any) is deliberately left as it is: forgetting it would leave the
                    // candidate with nothing to be decided from once the file is gone.
                    $failed[] = $path;
                    continue;
                }
                unset($pending[$path]);
            }
            if (isset($online[$path])) {
                // the site serves a version: the entry stays, because it is the only place a
                // reviewer can take that version down from
                $requested[] = $path;
                continue;
            }
            if (isset($manifest['entries'][$path])) {
                // only a target that really has an entry is "forgotten": the caller reports this list
                // as the number of entries the delete removed, and a target that only `pending/`
                // knew has none
                $forgotten[] = $path;
            } else {
                $dropped[] = $path;
            }
        }

        // The lock covers pure file work only: forget what the site never had, and record the
        // request for what it still serves.
        $this->mutate(static function (array $manifest) use ($forgotten, $requested, $at): array {
            foreach ($forgotten as $path) {
                unset($manifest['entries'][$path]);
            }

            return self::withFields($manifest, $requested, [
                'downRequest' => ['at' => $at, 'sourceDeleted' => true],
                // a new request is not the previous decision: the old note and its author go, and no
                // candidate is bound to this entry any more (its frozen copy was just discarded)
                'note' => '',
                'decidedBy' => '',
                'candidateDigest' => '',
            ]);
        });

        $this->dropArticleRows($forgotten);
        $this->dropArticleRows($dropped);

        return ['requested' => $requested, 'forgotten' => $forgotten, 'failed' => $failed];
    }

    /**
     * Withdraw a candidate still waiting in `pending/` for a source that is already gone.
     *
     * The bridge deletes `pending/<target>`; the operation is idempotent, so retrying the call the
     * delete path could not carry out is safe. The cached directory scans are dropped on success,
     * because the write that follows must be decided against the folders as they are now.
     *
     * @param string $owner the author the entry recorded, '' while it knows none
     * @return bool whether the candidate is gone
     */
    private function discardPendingCandidate(string $path, string $owner): bool
    {
        $uid = $this->authorOf($path, $owner);
        if ($uid === '') {
            $this->logger->warning(
                'pages: a candidate of a deleted source could not be withdrawn: its author is unknown, so no take-off request was recorded',
                ['target' => $path],
            );

            return false;
        }
        try {
            $this->bridge->reviewOp('discard', $uid, $path);
            $this->refreshTrees();

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'pages: a candidate of a deleted source could not be withdrawn; no take-off request was recorded',
                ['target' => $path, 'exception' => $e],
            );

            return false;
        }
    }

    /**
     * The author a target belongs to: the owner the entry recorded, or the first path segment under
     * the configured author root (an entry may not know its owner).
     */
    private function authorOf(string $path, string $owner): string
    {
        $owner = trim($owner);
        if ($owner !== '') {
            return $owner;
        }
        try {
            $root = trim($this->registry->getAuthorRoot(), '/');
        } catch (\Throwable) {
            return '';
        }
        $key = self::key($path);
        if ($root === '' || !str_starts_with($key, $root . '/')) {
            return '';
        }
        $rest = substr($key, strlen($root) + 1);
        $uid = strstr($rest, '/', true);

        return is_string($uid) ? $uid : '';
    }

    /**
     * The targets one of the given sources names: the source itself, or a path inside it (a folder).
     *
     * The candidates come from the three records (`entries`, `pending/`, `online/`), so this is a
     * plain prefix filter over a list, not a lookup in the manifest. The answer is deduplicated and
     * sorted, so one target reached through two records (an entry and `online/`, say) is handled once
     * and in a stable order.
     *
     * @param list<string> $targets normalised keys
     * @param list<string> $sources normalised keys
     * @return list<string>
     */
    private static function targetsUnder(array $targets, array $sources): array
    {
        $out = [];
        foreach ($targets as $path) {
            $path = (string)$path;
            foreach ($sources as $source) {
                if ($path === $source || str_starts_with($path, $source . '/')) {
                    $out[$path] = true;
                    break;
                }
            }
        }
        $paths = array_keys($out);
        sort($paths);

        return $paths;
    }

    /**
     * Drops the article cache rows of entries that no longer exist.
     *
     * @param list<string> $keys
     */
    private function dropArticleRows(array $keys): void
    {
        foreach ($keys as $key) {
            $row = $this->articles->findByPath('/' . $key);
            if ($row !== null) {
                $this->articles->deleteById((int)$row->getId());
            }
        }
    }

    /**
     * The entry of one article inside an already-read manifest, or [] while the manifest does not know
     * it.
     *
     * @param array<string,mixed> $manifest
     * @return array<string,mixed>
     */
    public static function entryOf(array $manifest, string $path): array
    {
        $key = self::key($path);
        $entry = $key === '' ? null : ($manifest['entries'][$key] ?? null);

        return is_array($entry) ? $entry : [];
    }

    /**
     * The author's pending take-down request of an article - the moment it was filed and whether the
     * author's file is already gone (`sourceDeleted`) - or null.
     *
     * @param array<string,mixed> $manifest
     * @return array{at:int,sourceDeleted:bool}|null
     */
    public static function downRequestOf(array $manifest, string $path): ?array
    {
        return self::downRequestSnapshot(self::entryOf($manifest, $path)['downRequest'] ?? null);
    }

    /**
     * The entries that carry a take-down request, keyed as the manifest keys them. The reverse index
     * needs them: such an entry describes a file that may already be gone, and it has to stay in the
     * reviewer's queue until somebody answers it.
     *
     * @param array<string,mixed> $manifest
     * @return list<string>
     */
    public static function downRequestKeys(array $manifest): array
    {
        $out = [];
        foreach ($manifest['entries'] as $path => $entry) {
            if (self::downRequestSnapshot(is_array($entry) ? ($entry['downRequest'] ?? null) : null) !== null) {
                $out[] = (string)$path;
            }
        }

        return $out;
    }

    /**
     * The manifest with fields written onto the listed articles. Everything an entry already knows is
     * kept - taking a decision back must not lose the moment a request was made - and only the keys
     * `$fields` names are replaced.
     *
     * @param array<string,mixed> $manifest
     * @param list<string> $keys normalised (site-relative, no leading slash)
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    private static function withFields(array $manifest, array $keys, array $fields): array
    {
        foreach ($keys as $key) {
            $existing = $manifest['entries'][$key] ?? null;
            $manifest['entries'][$key] = self::normaliseEntry(array_merge(
                is_array($existing) ? $existing : [],
                $fields,
            ));
        }

        return $manifest;
    }

    /**
     * The document with its own bookkeeping refreshed, its entries in the canonical shape and its map
     * in key order. The change callable produces content; the shape version and the key order belong to
     * the write, not to the caller.
     *
     * @param array<string,mixed> $manifest
     * @return array{version:int,entries:array<string,mixed>}
     */
    private static function stamped(array $manifest): array
    {
        $entries = [];
        foreach (is_array($manifest['entries'] ?? null) ? $manifest['entries'] : [] as $path => $entry) {
            $key = self::key((string)$path);
            if ($key === '' || !is_array($entry)) {
                continue;
            }
            $entries[$key] = self::normaliseEntry($entry);
        }
        ksort($entries);

        return ['version' => self::VERSION, 'entries' => $entries];
    }

    /**
     * @param list<string> $paths
     * @return list<string> the paths as the manifest keys them, deduplicated
     */
    private static function keys(array $paths): array
    {
        $out = [];
        foreach ($paths as $path) {
            $key = self::key((string)$path);
            if ($key !== '') {
                $out[$key] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * One path as the manifest keys it: site-relative, forward slashes, no leading slash. The same
     * article named `/_posts/x.md` or `_posts/x.md` is one entry, never two.
     */
    private static function key(string $path): string
    {
        return ltrim(trim(str_replace('\\', '/', $path)), '/');
    }

    /**
     * The document as PHP, or null when the file is not a JSON object. Everything a caller sees has been
     * through here: the file is hand-editable, so a stray key, a wrong type or an unknown field must
     * neither reach the writers nor throw.
     *
     * @return array{version:int,entries:array<string,array<string,mixed>>}|null
     */
    private static function decode(string $json): ?array
    {
        if (trim($json) === '') {
            return self::empty();
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            return null;
        }
        $version = self::intOf($decoded['version'] ?? 0);

        return [
            'version' => $version > 0 ? $version : self::VERSION,
            'entries' => self::articleEntries($decoded['entries'] ?? null),
        ];
    }

    /** @return array{version:int,entries:array<string,array<string,mixed>>} */
    private static function empty(): array
    {
        return ['version' => self::VERSION, 'entries' => []];
    }

    /**
     * The `entries` map, normalised. A path with `..` in it is dropped: the key travels into file
     * lookups and into the bridge's targets, and a hand-edited file must not point either of them
     * outside the site.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function articleEntries(mixed $raw): array
    {
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $path => $entry) {
            $key = self::key((string)$path);
            if ($key === '' || str_contains($key, '..') || !is_array($entry)) {
                continue;
            }
            $out[$key] = self::normaliseEntry($entry);
        }

        return $out;
    }

    /**
     * One article entry in the documented shape: every field present, so no writer and no cache sync
     * has to guess whether a key exists.
     *
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private static function normaliseEntry(array $entry): array
    {
        return [
            'owner' => trim(self::strOf($entry['owner'] ?? '')),
            // the sha256 of the frozen candidate the approval is bound to, '' while none waits
            'candidateDigest' => trim(self::strOf($entry['candidateDigest'] ?? '')),
            'downRequest' => self::downRequestSnapshot($entry['downRequest'] ?? null),
            'decidedBy' => trim(self::strOf($entry['decidedBy'] ?? '')),
            'note' => self::strOf($entry['note'] ?? ''),
        ];
    }

    /**
     * One **take-down request** out of a hand-editable file, in the shape this document documents:
     * `{at:int, sourceDeleted:bool}` - or null when the value is not one.
     *
     * A value without a positive `at` is not a request (so `true`, a bare string, a list, `{at: 0}` and
     * a missing key all mean "nothing recorded"). `sourceDeleted` is read as a real boolean and nothing
     * else: a hand-edited `"yes"` or `1` must not turn "the source is still there" into "transfer it" -
     * the wrong reading of this flag moves a page the author still maintains.
     *
     * @return array{at:int,sourceDeleted:bool}|null
     */
    private static function downRequestSnapshot(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $at = self::intOf($value['at'] ?? 0);
        if ($at <= 0) {
            return null;
        }

        return [
            'at' => $at,
            'sourceDeleted' => ($value['sourceDeleted'] ?? null) === true,
        ];
    }

    /** A number out of a hand-editable file: anything that is not one reads as 0 rather than throwing. */
    private static function intOf(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int)$value) : 0;
    }

    /** A string out of a hand-editable file: an array or an object reads as '' rather than throwing. */
    private static function strOf(mixed $value): string
    {
        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * The document as the file is written - sorted keys, two-space indent, trailing newline, UTF-8. The
     * manifest is meant to be read, diffed and edited by hand, so its exact bytes are part of the
     * interface.
     *
     * @param array<string,mixed> $manifest as `stamped()` left it (canonical order)
     */
    private function encode(array $manifest): string
    {
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new \InvalidArgumentException(
                $this->l10n->t('The review manifest could not be written: %s', [json_last_error_msg()]),
            );
        }
        // PHP indents pretty-printed JSON with four spaces; the manifest is specified with two
        $json = (string)preg_replace_callback(
            '/^( +)/m',
            static fn (array $match): string => str_repeat(' ', intdiv(strlen($match[1]), 2)),
            $json,
        );

        return $json . "\n";
    }

    /** The app's own folder under Nextcloud's appdata - `appdata_<instanceid>/pages`. */
    private function folder(): ISimpleFolder
    {
        return $this->appData->getFolder('/');
    }

    /** The manifest file, or null while it is not there. */
    private function currentFile(): ?ISimpleFile
    {
        $folder = $this->folder();
        try {
            return $folder->fileExists(self::FILE) ? $folder->getFile(self::FILE) : null;
        } catch (NotFoundException) {
            return null;
        }
    }

    /**
     * The stored document with the ETag it was read at (null while the file is not there), plus the
     * reason a read failed ('' when it did not) and whether that failure is a corrupt document.
     *
     * @return array{json:string,etag:?string,error:string,corrupt:bool}
     */
    private function load(): array
    {
        try {
            $file = $this->currentFile();
            if ($file === null) {
                $this->corruption = '';

                return ['json' => '', 'etag' => null, 'error' => '', 'corrupt' => false];
            }
            $json = $file->getContent();
            $etag = $file->getETag();
            if (trim($json) === '') {
                $this->corruption = '';

                return ['json' => '', 'etag' => $etag, 'error' => '', 'corrupt' => false];
            }
            if (!self::parsable($json)) {
                $this->corruption = $this->l10n->t('the review manifest is not valid JSON');
                $this->reportCorruption(strlen($json));

                return ['json' => $json, 'etag' => $etag, 'error' => $this->corruption, 'corrupt' => true];
            }
            $this->corruption = '';

            return ['json' => $json, 'etag' => $etag, 'error' => '', 'corrupt' => false];
        } catch (\Throwable $e) {
            $this->corruption = '';

            return ['json' => '', 'etag' => null, 'error' => $e->getMessage(), 'corrupt' => false];
        }
    }

    /**
     * Whether the bytes are JSON at all - not whether they are a manifest: an array or a scalar decodes
     * fine and still carries no entry, so {@see self::decode()} answers null for it and a write replaces
     * it. Only a syntax error counts as corruption.
     */
    private static function parsable(string $json): bool
    {
        json_decode($json, true);

        return json_last_error() === JSON_ERROR_NONE;
    }

    /** The one warning a request gets about a corrupt manifest, with where it is and how big it is. */
    private function reportCorruption(int $size): void
    {
        if ($this->corruptionReported) {
            return;
        }
        $this->corruptionReported = true;
        try {
            $path = $this->location();
        } catch (\Throwable) {
            $path = '';
        }
        $this->logger->warning(
            'pages: the review manifest is not valid JSON: it reads as the empty manifest, and every write over it is refused',
            ['path' => $path, 'size' => $size],
        );
    }

    /**
     * Copies a corrupt manifest aside, once, before the app refuses to write over it.
     *
     * The file itself is never touched: its bytes may be the only copy of the decisions, and a repair
     * by hand needs them. One copy is enough - the first corrupt bytes are the evidence.
     */
    private function preserveCorrupt(string $json): void
    {
        try {
            $folder = $this->folder();
            foreach ($folder->getDirectoryListing() as $file) {
                if (str_starts_with($file->getName(), self::CORRUPT_PREFIX)) {
                    return;
                }
            }
            $folder->newFile(self::CORRUPT_PREFIX . date('Ymd-His') . '.json', $json);
        } catch (\Throwable $e) {
            $this->logger->warning('pages: the corrupt review manifest could not be copied aside', [
                'path' => self::CORRUPT_PREFIX . '*.json',
                'exception' => $e,
            ]);
        }
    }

    /**
     * Writes the document, and answers whether the file was still the one that was read. A `false`
     * means it was not (there is something to compare and it differs, or a file appeared where none
     * was), and the caller answers that by re-reading and applying the change again.
     *
     * Two comparisons, for two different failures: the ETag catches the ordinary case, and the content
     * comparison catches a file replaced twice in one second (a local storage keeps the ETag then).
     *
     * @param array<string,mixed> $manifest
     * @param array{json:string,etag:?string,error:string,corrupt:bool} $loaded
     * @throws \InvalidArgumentException when the document cannot be encoded
     */
    private function write(array $manifest, array $loaded): bool
    {
        $json = $this->encode($manifest);
        $file = $this->currentFile();
        if ($file === null) {
            if ($loaded['etag'] !== null) {
                return false;
            }
            $this->folder()->newFile(self::FILE, $json);

            return true;
        }
        if ($loaded['etag'] === null) {
            return false;
        }
        try {
            $current = $file->getContent();
        } catch (NotFoundException) {
            return false;
        }
        $sameContent = $current === $loaded['json']
            || (trim($loaded['json']) === '' && trim($current) === '');
        if (!$sameContent || ($loaded['etag'] !== '' && $file->getETag() !== $loaded['etag'])) {
            return false;
        }
        $file->putContent($json);

        return true;
    }

    /**
     * The exclusive lock for a whole read-modify-write cycle.
     *
     * @throws ConflictException when another writer holds the lock
     */
    private function acquire(): void
    {
        try {
            $this->locking->acquireLock(self::LOCK_KEY, ILockingProvider::LOCK_EXCLUSIVE);
        } catch (LockedException) {
            throw new ConflictException(
                $this->l10n->t('The review manifest is being written right now; nothing was recorded, please try again.'),
                ['locked' => self::LOCK_KEY],
            );
        }
    }

    /** Releases the lock. Deliberately never called on a path where the lock was not acquired. */
    private function release(): void
    {
        $this->locking->releaseLock(self::LOCK_KEY, ILockingProvider::LOCK_EXCLUSIVE);
    }

    /**
     * The database cache follows the manifest: `pages_articles` carries the state and the review trail -
     * the queue, the counts and the admin panel are read from it - while "what the site has" is answered
     * by the `online/` folder.
     *
     * Only entries that really differ are written, so a submit does not rewrite every row of the table,
     * and a row that drifted is repaired by the next write.
     *
     * @param array<string,mixed> $manifest the document as it was just written
     */
    private function syncCaches(array $manifest): void
    {
        $this->syncArticles(is_array($manifest['entries'] ?? null) ? $manifest['entries'] : []);
    }

    /**
     * Write the article rows the manifest describes. A row the manifest does not name is deliberately
     * left alone: `pages_articles` also carries a cache row for every site-owned post, which has no
     * manifest entry at all, so an empty `$entries` is not a request to truncate the table.
     *
     * @param array<string,array<string,mixed>> $entries
     */
    private function syncArticles(array $entries): void
    {
        if ($entries === []) {
            return;
        }
        $rows = [];
        foreach ($this->articles->findAll() as $row) {
            $rows[(string)$row->getPath()] = $row;
        }
        foreach ($entries as $path => $entry) {
            $path = (string)$path;
            $row = $rows['/' . $path] ?? null;
            if ($row === null) {
                continue;
            }
            $state = self::downRequestSnapshot($entry['downRequest'] ?? null) !== null
                ? Article::STATE_REQUESTED
                : ($this->isLive($path) ? Article::STATE_EXTERNAL : Article::STATE_INTERNAL);
            $pendingAt = self::pendingAt($entry);
            // the moment the site's version was written is the online file's own mtime; the row
            // carries it only for the panel, never as a decision
            $publishedAt = $this->liveStamp($path);
            if ((string)$row->getState() === $state
                && (string)($row->getReviewNote() ?? '') === (string)$entry['note']
                && ($pendingAt === 0 || (int)$row->getSubmittedAt() === $pendingAt)
                && ($publishedAt === 0 || (int)$row->getPublishedAt() === $publishedAt)) {
                continue;
            }
            // 0 means "keep what the row has", exactly like the mapper's partial write intends
            $this->articles->saveState(
                (int)$row->getId(),
                $state,
                (string)$entry['note'],
                $pendingAt,
                $publishedAt,
                '',
            );
        }
    }

    /** The moment the pending request of an entry was filed, 0 while nothing is pending. */
    private static function pendingAt(array $entry): int
    {
        $downRequest = self::downRequestSnapshot($entry['downRequest'] ?? null);

        return $downRequest['at'] ?? 0;
    }
}
