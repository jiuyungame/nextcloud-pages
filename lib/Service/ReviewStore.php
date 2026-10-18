<?php

declare(strict_types=1);

namespace OCA\Pages\Service;

use OCA\Pages\Db\Article;
use OCA\Pages\Db\ArticleMapper;
use OCA\Pages\Db\PublishedMapper;
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
 * The visibility manifest: which article sits in which state, and which files the site has.
 *
 * It is a file and not a table because those two answers - may this article go out, is that file on the
 * site - have to survive a rebuild of the app's database and be restorable by hand. It lives in the
 * app's own storage under Nextcloud's appdata (`appdata_<instanceid>/pages/review.json`), which is
 * private to the server: invisible in the Files app, never served over HTTP, not in anybody's file tree.
 * That is the point - the record of who decided what about whose article must not be a file the author
 * of that article can edit, and the site storage user can be an author. It used to live in exactly that
 * user's files, next to the site folder; {@see self::importLegacy()} moves such a file in once.
 *
 * The database is its cache: `pages_articles` and `pages_published` are written from the manifest after
 * every change (see {@see self::syncCaches()}), which keeps the request list and the counts plain
 * queries while every decision about a state or about what the site has is read from the manifest.
 *
 * ```json
 * {
 *   "version": 2,
 *   "updatedAt": 1792041500,
 *   "entries": {
 *     "tmp/alice/2026-05-01-hello.md": {
 *       "state": "external", "owner": "alice", "submittedAt": 1792040000, "decidedBy": "bob",
 *       "intent": "", "note": "", "publishedAt": 1792041500, "revision": "8f2c1a9d"
 *     },
 *     "tmp/alice/2026-05-02-waiting.md": {
 *       "state": "requested", "owner": "alice", "submittedAt": 1792042000, "decidedBy": "",
 *       "intent": "online", "note": "", "publishedAt": 0, "revision": ""
 *     }
 *   },
 *   "published": {
 *     "tmp/alice/2026-05-01-hello.md": { "owner": "alice", "revision": "8f2c1a9d", "publishedAt": 1792041500 }
 *   }
 * }
 * ```
 *
 * Keys are site-relative paths without a leading slash. `entries` holds the visibility state of every
 * *author* file - an image is an entry exactly like a page, and a file the manifest does not mention is
 * `internal` - and `published` holds what the last accepted commit put on the site (an author's file and
 * the site's own alike). A path on that list whose file is gone is a removal the next publish has to
 * carry out. A manifest written by the pre-v2 layout keeps its `articles` key; it is read as `entries`
 * and rewritten on the next write.
 *
 * A `requested` entry also carries `intent`: `online` means the author asked for the entry to go live,
 * `offline` that they asked for it to come off the site. The two directions are what tell the reviewer
 * which decision they are making, and {@see self::onSite()} reads the same pair: a pending take-offline
 * request is still on the site until it is decided, a pending online request is not on it yet.
 *
 * A missing, empty or unparsable file reads as the empty manifest, which is what a site nobody has
 * reviewed anything on yet looks like: every article internal, nothing on the site. The first write
 * replaces it with a complete document.
 */
final class ReviewStore
{
    /** The manifest's name inside the app's own appdata folder. */
    public const FILE = 'review.json';

    /**
     * Where the manifest used to live: a folder of that name in the site storage user's own files. It is
     * read once, on import, and written nowhere.
     */
    public const LEGACY_DIR = 'Pages';

    /** Shape of the document, so a later change can be told apart from an older file. */
    public const VERSION = 2;

    /** How often a write is retried while somebody else keeps changing the manifest. */
    private const WRITE_ATTEMPTS = 3;

    /**
     * The lock every writer takes for the whole read-modify-write cycle. The string is a name of the
     * app's own - the provider only compares it, it is not a path the Files API knows.
     */
    private const LOCK_KEY = 'pages/review.json';

    /**
     * Whether the old location was already looked at in this request; the import is attempted once, so
     * a page view costs at most one look at a file that is not supposed to be there any more.
     */
    private bool $legacyChecked = false;

    public function __construct(
        private IAppData $appData,
        private ILockingProvider $locking,
        private FileService $files,
        private SiteRegistry $registry,
        private IConfig $config,
        private ArticleMapper $articles,
        private PublishedMapper $published,
        private LoggerInterface $logger,
        private IL10N $l10n,
    ) {
    }

    /**
     * The whole manifest. Never throws: a file that is missing, empty or unparsable reads as the empty
     * manifest, so no page breaks over a bookkeeping file that is not there yet.
     *
     * @return array{version:int,updatedAt:int,entries:array<string,array<string,mixed>>,published:array<string,array<string,mixed>>}
     */
    public function read(): array
    {
        return self::decode($this->loadOrImport(false)['json']) ?? self::empty();
    }

    /**
     * The state of one article. An article the manifest does not mention is `internal`: nothing is
     * approved for the site before somebody approved it.
     */
    public function state(string $path): string
    {
        return self::stateOf($this->read(), $path);
    }

    /**
     * The entry of one article, or [] while the manifest does not know it (which reads as "internal,
     * never requested, never published").
     *
     * @return array<string,mixed>
     */
    public function entry(string $path): array
    {
        return self::entryOf($this->read(), $path);
    }

    /**
     * The published list, keyed by path: what the last accepted commit put on the site.
     *
     * @return array<string,array{owner:string,revision:string,publishedAt:int}>
     */
    public function published(): array
    {
        return $this->read()['published'];
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
     * The document exactly as the file holds it, without the normalising read: only the one-shot
     * visibility migration asks for this, because it has to see state values (`draft`, `submitted`, …)
     * that the normal shape no longer carries.
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

    /**
     * Where the manifest lives, as an administrator recognises it:
     * `appdata_<instanceid>/pages/review.json`.
     */
    public function location(): string
    {
        return 'appdata_' . $this->config->getSystemValueString('instanceid')
            . '/' . $this->folder()->getName() . '/' . self::FILE;
    }

    /**
     * Whether the manifest still sits at the location it used to have, in the site storage user's own
     * files. Nothing reads it from there any more: the doctor reports it so that a copy left behind by a
     * failed cleanup is not forgotten.
     */
    public function legacyExists(): bool
    {
        $user = $this->storageUser();
        if ($user === '') {
            return false;
        }
        try {
            return $this->files->fileExists($user, self::LEGACY_DIR, self::FILE);
        } catch (\Throwable $e) {
            // an account that is gone or a backend that will not answer: there is nothing to import, and
            // a read must not fail over the question
            $this->logger->debug('pages: the old review manifest location could not be checked', [
                'user' => $user,
                'exception' => $e,
            ]);

            return false;
        }
    }

    /**
     * The one write path: read the manifest, apply `$change`, write the result back, then sync the
     * database caches from it. The whole cycle runs under an exclusive lock, so two review actions
     * cannot interleave read-apply-write and lose each other's entry.
     *
     * The two guards below are not redundant. The lock orders the writers that go through this app (and
     * an instance with file locking switched off gets Nextcloud's no-op provider, which orders
     * nothing), while the content and ETag comparison in {@see self::write()} catches a file that was
     * replaced behind its back - a restored backup, a copy an administrator put there, a second
     * installation sharing the storage. On such a change the document is re-read and `$change` is
     * applied to the fresh one, up to self::WRITE_ATTEMPTS, and then the write gives up and says so.
     *
     * @param callable(array<string,mixed>):array<string,mixed> $change
     * @return array<string,mixed> the manifest as it was written
     * @throws NotPermittedException when the manifest exists but cannot be read: overwriting a document
     *         this app cannot see would throw away every decision it holds
     * @throws ConflictException when another writer holds the lock, or kept replacing the file
     * @throws \RuntimeException when the manifest was written but the caches could not follow it
     */
    public function mutate(callable $change): array
    {
        $this->acquire();
        try {
            for ($attempt = 1; $attempt <= self::WRITE_ATTEMPTS; $attempt++) {
                $loaded = $this->loadOrImport(true);
                if ($loaded['error'] !== '') {
                    throw new NotPermittedException(
                        $this->l10n->t('The review manifest could not be read: %s', [$loaded['error']]),
                    );
                }

                $updated = self::stamped($change(self::decode($loaded['json']) ?? self::empty()), time());
                if (!$this->write($updated, $loaded)) {
                    // the file is not the one that was read: take the fresh document and apply the change
                    // to that one, so nothing another writer recorded is dropped
                    continue;
                }

                try {
                    $this->syncCaches($updated);
                } catch (\Throwable $e) {
                    // The decision is recorded - the manifest is the truth - but the cache the queue and
                    // the counts are read from is not. Saying that is the honest answer; the next write
                    // syncs the whole manifest again, so the drift is repaired by the following action.
                    throw new \RuntimeException(
                        $this->l10n->t('The review manifest was written, but the article cache could not be updated: %s', [$e->getMessage()]),
                        0,
                        $e,
                    );
                }

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
     * Move one or more articles to a state, in one write: a decision about a queue is one fact, and
     * writing the manifest per article would mean a whole read-modify-write cycle per article.
     *
     * @param list<string> $paths
     * @param array<string,mixed> $fields what else every entry learns (note, decidedBy, intent, submittedAt, owner)
     */
    public function setState(array $paths, string $state, array $fields = []): void
    {
        $keys = self::keys($paths);
        if ($keys === []) {
            return;
        }
        $this->mutate(static fn (array $manifest): array => self::withState($manifest, $keys, $state, $fields));
    }

    /**
     * The one-shot rewrite of a document written before the three-state model: the old state values are
     * mapped, `reviewedBy` becomes `decidedBy`, the request marks are dropped, and a mapped
     * `requested` entry learns the `intent` its old state implies.
     *
     * It reads the file as it is ({@see self::rawJson()}) because `read()` normalises an unknown state
     * to `internal` - the information this rewrite needs. The result is written through the same
     * guards as every other write, and the caches are synced from it, so the migration is one step for
     * both the manifest and the tables.
     *
     * @param array<string,string> $stateMap old state value => new state value
     * @param array<string,string> $intentOf old state value => intent for an entry that maps to `requested`
     * @return array{entries:int,written:bool,changed:int,states:array<string,array<string,int>>} how many
     *         entries the document has, whether it had to be rewritten, how many carried a value of the
     *         old shape, and how many moved from which old state to which new one
     */
    public function migrateStates(array $stateMap, array $intentOf): array
    {
        $this->acquire();
        try {
            $loaded = $this->load();
            if ($loaded['error'] !== '') {
                throw new NotPermittedException(
                    $this->l10n->t('The review manifest could not be read: %s', [$loaded['error']]),
                );
            }
            if ($loaded['json'] === '') {
                // there is no manifest: every article is internal already, and creating a document
                // just to say so would turn "never written" into "written with nothing in it"
                return ['entries' => 0, 'written' => false, 'changed' => 0, 'states' => []];
            }
            $raw = json_decode($loaded['json'], true);
            if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
                throw new NotPermittedException(
                    $this->l10n->t('The review manifest could not be read: %s', ['not a JSON object']),
                );
            }
            $entries = [];
            $states = [];
            $legacy = 0;
            foreach ((array)($raw['entries'] ?? $raw['articles'] ?? []) as $path => $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $key = self::key((string)$path);
                if ($key === '') {
                    continue;
                }
                $old = self::strOf($entry['state'] ?? '');
                $new = $stateMap[$old] ?? $old;
                $intent = $new === Article::STATE_REQUESTED ? ($intentOf[$old] ?? '') : '';
                $mapped = [...$entry, 'state' => $new, 'intent' => $intent];
                // `reviewedBy` is the old name of the same fact, and the two request marks belong to
                // the retired offline/deletion workflow
                $mapped['decidedBy'] = self::strOf($entry['decidedBy'] ?? $entry['reviewedBy'] ?? '');
                if ($old !== $new
                    || array_key_exists('reviewedBy', $entry)
                    || array_key_exists('offline', $entry)
                    || array_key_exists('deletion', $entry)) {
                    $legacy++;
                }
                unset($mapped['reviewedBy'], $mapped['offline'], $mapped['deletion']);
                $entries[$key] = self::normaliseEntry($mapped);
                $states[$old][$new] = ($states[$old][$new] ?? 0) + 1;
            }
            $document = self::stamped([
                'entries' => $entries,
                'published' => (array)($raw['published'] ?? []),
            ], time());
            // a document already in the current shape is not rewritten: the migration runs once per
            // install, and a second boot must leave the file's bytes alone
            $written = false;
            if ($legacy > 0 || self::intOf($raw['version'] ?? 0) !== self::VERSION) {
                $written = $this->write($document, $loaded);
                if ($written) {
                    $this->syncCaches($document);
                }
            }

            return ['entries' => count($entries), 'written' => $written, 'changed' => $legacy, 'states' => $states];
        } finally {
            $this->release();
        }
    }

    /**
     * Record what a commit put on the site. Called with the paths the bridge actually accepted, so a
     * failed publish leaves the list as it was and the next attempt picks the files up again.
     *
     * @param array<string,string> $paths path => owner uid ('' for the site's own files)
     * @return int the number of entries that are new or learned a new revision
     */
    public function record(array $paths, string $revision): int
    {
        $entries = [];
        foreach ($paths as $path => $owner) {
            $key = self::key((string)$path);
            if ($key !== '') {
                $entries[$key] = trim((string)$owner);
            }
        }
        if ($entries === []) {
            return 0;
        }

        $recorded = 0;
        $this->mutate(static function (array $manifest) use ($entries, $revision, &$recorded): array {
            // `$change` runs again after a conflict, so the count starts over with the fresh document
            $recorded = 0;
            $now = time();
            foreach ($entries as $key => $owner) {
                $existing = $manifest['published'][$key] ?? null;
                if (is_array($existing)
                    && (string)($existing['owner'] ?? '') === $owner
                    && (string)($existing['revision'] ?? '') === $revision) {
                    // already on the list as of this revision: the entry keeps its moment
                    continue;
                }
                $manifest['published'][$key] = ['owner' => $owner, 'revision' => $revision, 'publishedAt' => $now];
                $recorded++;
            }

            return $manifest;
        });

        return $recorded;
    }

    /**
     * Forget paths: they are not on the site any more (deleted, renamed, or taken down).
     *
     * @param list<string> $paths
     * @return list<string> the paths that were on the list
     */
    public function forget(array $paths): array
    {
        $keys = self::keys($paths);
        if ($keys === []) {
            return [];
        }

        $removed = [];
        $this->mutate(static function (array $manifest) use ($keys, &$removed): array {
            // as in record(): a retry applies the whole change to the fresh document
            $removed = [];
            foreach ($keys as $key) {
                if (!isset($manifest['published'][$key])) {
                    continue;
                }
                unset($manifest['published'][$key]);
                $removed[] = $key;
            }

            return $manifest;
        });

        return $removed;
    }

    /**
     * Forget articles: the file is gone, so nothing about them is left to decide. The `published`
     * list is deliberately left alone - a path the site still serves is a removal the next publish
     * has to carry out (see ArticleReviewService::classify()); forgetting it here would drop that
     * work on the floor.
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
            // a retry applies the whole change to the fresh document, like record() and forget()
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

        foreach ($removed as $key) {
            $row = $this->articles->findByPath('/' . $key);
            if ($row !== null) {
                $this->articles->deleteById((int)$row->getId());
            }
        }

        return $removed;
    }

    /**
     * The state of one article inside an already-read manifest. A scan asks this per article, which is
     * what keeps it at one read of the file instead of one read per post.
     *
     * @param array<string,mixed> $manifest as read() or mutate() returned it
     */
    public static function stateOf(array $manifest, string $path): string
    {
        $entry = self::entryOf($manifest, $path);

        return (string)($entry['state'] ?? Article::STATE_INTERNAL);
    }

    /**
     * The intent of the pending request on an article: `online`, `offline`, or '' when nothing is
     * requested. The pair state+intent is what {@see self::onSite()} and the request panel read.
     *
     * @param array<string,mixed> $manifest as read() or mutate() returned it
     */
    public static function intentOf(array $manifest, string $path): string
    {
        $entry = self::entryOf($manifest, $path);
        if (($entry['state'] ?? '') !== Article::STATE_REQUESTED) {
            return '';
        }
        $intent = (string)($entry['intent'] ?? '');

        return in_array($intent, Article::INTENTS, true) ? $intent : '';
    }

    /**
     * The entry of one article inside an already-read manifest, or [] while the manifest does not know
     * it.
     *
     * @param array<string,mixed> $manifest as read() or mutate() returned it
     * @return array<string,mixed>
     */
    public static function entryOf(array $manifest, string $path): array
    {
        $key = self::key($path);
        $entry = $key === '' ? null : ($manifest['entries'][$key] ?? null);

        return is_array($entry) ? $entry : [];
    }

    /**
     * Whether the entry is approved for the site, i.e. may be mirrored, committed and served.
     *
     * `external` is the approval; a pending **take-offline** request is still approved (the article is
     * public until a reviewer decides, which is the whole point of asking first), while a pending
     * online request is not on the site yet. This one answer is asked by the mirror list, the publish
     * sweep and the reading list, so "on the site" cannot mean two things.
     *
     * @param array<string,mixed> $manifest as read() or mutate() returned it
     */
    public static function onSite(array $manifest, string $path): bool
    {
        $state = self::stateOf($manifest, $path);
        if ($state === Article::STATE_EXTERNAL) {
            return true;
        }

        return $state === Article::STATE_REQUESTED && self::intentOf($manifest, $path) === Article::INTENT_OFFLINE;
    }

    /**
     * Whether a path is on the published list of an already-read manifest: a fact of the commit, not
     * of the visibility state. An `external` entry that has not been published yet is approved for the
     * site but not on it.
     *
     * @param array<string,mixed> $manifest as read() or mutate() returned it
     */
    public static function isPublished(array $manifest, string $path): bool
    {
        $key = self::key($path);

        return $key !== '' && isset($manifest['published'][$key]);
    }

    /**
     * The manifest with a state applied to the listed articles. Everything an entry already knows is
     * kept - taking a decision back must not lose the moment the request was made or the fact that an
     * older revision is on the site - and only `$fields` is written on top of it.
     *
     * @param array<string,mixed> $manifest
     * @param list<string> $keys normalised (site-relative, no leading slash)
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    private static function withState(array $manifest, array $keys, string $state, array $fields): array
    {
        // an unknown state would be a value nothing can move on, so it reads as internal
        $state = in_array($state, Article::STATES, true) ? $state : Article::STATE_INTERNAL;
        foreach ($keys as $key) {
            $existing = $manifest['entries'][$key] ?? null;
            // the state is merged in *before* the entry is normalised: the shape decides whether the
            // intent belongs to it (`requested` keeps it, every other state clears it)
            $entry = self::normaliseEntry(array_merge(
                is_array($existing) ? $existing : [],
                $fields,
                ['state' => $state],
            ));
            $manifest['entries'][$key] = $entry;
        }

        return $manifest;
    }

    /**
     * The document with its own bookkeeping refreshed and its maps in key order. The change callable
     * produces content; the shape version, the write moment and the canonical order belong to the write,
     * not to the caller - so the in-memory document is the one the file holds, and a diff shows only
     * what really changed.
     *
     * @param array<string,mixed> $manifest
     * @return array{version:int,updatedAt:int,entries:array<string,mixed>,published:array<string,mixed>}
     */
    private static function stamped(array $manifest, int $now): array
    {
        $entries = is_array($manifest['entries'] ?? null) ? $manifest['entries'] : [];
        $published = is_array($manifest['published'] ?? null) ? $manifest['published'] : [];
        ksort($entries);
        ksort($published);

        return [
            'version' => self::VERSION,
            'updatedAt' => $now,
            'entries' => $entries,
            'published' => $published,
        ];
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
     * through here: the file is hand-editable, so a stray key, a wrong type or an unknown state must
     * neither reach the writers (the publish step iterates the published map, the workflow the states)
     * nor throw.
     *
     * @return array{version:int,updatedAt:int,entries:array<string,array<string,mixed>>,published:array<string,array<string,mixed>>}|null
     */
    private static function decode(string $json): ?array
    {
        if (trim($json) === '') {
            return self::empty();   // an empty file is an empty manifest, not a broken one
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            return null;            // not a JSON object: nothing in it can be trusted
        }
        $version = self::intOf($decoded['version'] ?? 0);

        return [
            'version' => $version > 0 ? $version : self::VERSION,
            'updatedAt' => self::intOf($decoded['updatedAt'] ?? 0),
            // `articles` is the pre-v2 spelling of the same section: read once, rewritten as `entries`
            'entries' => self::articleEntries($decoded['entries'] ?? $decoded['articles'] ?? null),
            'published' => self::publishedEntries($decoded['published'] ?? null),
        ];
    }

    /**
     * @return array{version:int,updatedAt:int,entries:array<string,array<string,mixed>>,published:array<string,array<string,mixed>>}
     */
    private static function empty(): array
    {
        return ['version' => self::VERSION, 'updatedAt' => 0, 'entries' => [], 'published' => []];
    }

    /**
     * The `entries` map, normalised. A path with `..` in it is dropped: the key travels into file
     * lookups and into the publish step, and a hand-edited file must not be able to point either of
     * them outside the site.
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

    /** @return array<string,array{owner:string,revision:string,publishedAt:int}> */
    private static function publishedEntries(mixed $raw): array
    {
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $path => $entry) {
            $key = self::key((string)$path);
            if ($key === '' || str_contains($key, '..') || !is_array($entry)) {
                continue;
            }
            $out[$key] = [
                'owner' => trim(self::strOf($entry['owner'] ?? '')),
                'revision' => self::strOf($entry['revision'] ?? ''),
                'publishedAt' => self::intOf($entry['publishedAt'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * One article entry in the documented shape: every field present, so no writer and no cache sync has
     * to guess whether a key exists. `intent` travels with the state and is empty for every state but
     * `requested`; a document from before this shape has no `intent` and no `decidedBy` and reads as
     * "nothing requested, nobody decided".
     *
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private static function normaliseEntry(array $entry): array
    {
        $state = self::strOf($entry['state'] ?? '');
        $state = in_array($state, Article::STATES, true) ? $state : Article::STATE_INTERNAL;
        $intent = self::strOf($entry['intent'] ?? '');
        $decidedBy = trim(self::strOf($entry['decidedBy'] ?? $entry['reviewedBy'] ?? ''));

        return [
            'state' => $state,
            'owner' => trim(self::strOf($entry['owner'] ?? '')),
            'submittedAt' => self::intOf($entry['submittedAt'] ?? 0),
            'decidedBy' => $decidedBy,
            'intent' => $state === Article::STATE_REQUESTED && in_array($intent, Article::INTENTS, true) ? $intent : '',
            'note' => self::strOf($entry['note'] ?? ''),
            'publishedAt' => self::intOf($entry['publishedAt'] ?? 0),
            'revision' => self::strOf($entry['revision'] ?? ''),
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
            // an entry that is not valid UTF-8 (a review note, typically): failing here keeps the old
            // document, while writing the failed encoding would replace it with an empty - i.e. wiped -
            // manifest
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

    /**
     * The app's own folder under Nextcloud's appdata - `appdata_<instanceid>/pages`. IAppData is already
     * scoped to this app (the factory behind it takes the app id), so its own root is what this asks
     * for: a folder named after the app again would nest it one level deeper. Nextcloud creates that
     * root on demand, the way it does for every app that stores something, so a first read, a first
     * write and a later read all find the same folder.
     */
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
            // it vanished between the check and the read: the same answer as "not there"
            return null;
        }
    }

    /**
     * The stored document with the ETag it was read at (null while the file is not there), plus the
     * reason a read failed ('' when it did not).
     *
     * A missing file is not a failure - it is the state before the first review action - but a file that
     * is there and cannot be read is one, and the difference matters: `read()` answers the empty
     * manifest either way, while `mutate()` refuses to overwrite a document it cannot see.
     *
     * @return array{json:string,etag:?string,error:string}
     */
    private function load(): array
    {
        try {
            $file = $this->currentFile();
            if ($file === null) {
                return ['json' => '', 'etag' => null, 'error' => ''];
            }
            $json = $file->getContent();

            return ['json' => $json, 'etag' => $file->getETag(), 'error' => ''];
        } catch (\Throwable $e) {
            // carried out of here instead of thrown: `read()` has to answer (the reading list depends on
            // it) while `mutate()` turns this into a refusal to write
            return ['json' => '', 'etag' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * The stored document, after giving the manifest at the old location its one chance to move in.
     *
     * Importing is a write, so it happens under the same lock a change takes - except when the caller
     * already holds that lock (`mutate()` does): the provider's lock is not a recursive one, taking it
     * twice in one process is an error, and the caller holding it is already the protection.
     *
     * @param bool $lockHeld whether the caller holds the manifest lock
     * @return array{json:string,etag:?string,error:string}
     */
    private function loadOrImport(bool $lockHeld): array
    {
        $loaded = $this->load();
        if ($loaded['etag'] !== null || $loaded['error'] !== '') {
            // there is a manifest already (appdata wins, the old file is never copied over it), or it
            // cannot be read (importing over it would be worse)
            return $loaded;
        }
        if (!$lockHeld && $this->legacyChecked) {
            // a read looked at the old location already in this request; a write does not take that as an
            // answer, because a change written on top of a manifest that was never imported would bury it
            return $loaded;
        }
        $this->legacyChecked = true;
        if (!$this->legacyExists()) {
            return $loaded;
        }
        if (!$lockHeld) {
            try {
                $this->acquire();
            } catch (ConflictException $e) {
                // somebody is importing or reviewing right now: a read must not fail over that, so it
                // answers what is there (nothing) and the next read imports
                $this->logger->debug('pages: the review manifest is being written, reading it without the import', [
                    'exception' => $e,
                ]);

                return $loaded;
            }
        }
        try {
            return $this->importLegacy() ?? $this->load();
        } finally {
            if (!$lockHeld) {
                $this->release();
            }
        }
    }

    /**
     * The one-time import from the location the manifest used to have.
     *
     * `Pages/review.json` lived in the site storage user's own files, next to the site folder. That user
     * can be an author, and an author must not be able to edit the record of their own review decisions,
     * which is why the manifest moved into appdata. The old file is read once, written into appdata
     * verbatim, and then deleted: a copy left behind would still be a writable record of the decisions.
     *
     * Best effort by design, and idempotent. A legacy file that cannot be read is logged and the app
     * continues with the empty manifest (every article a draft, nothing on the site, which the doctor
     * reports as a missing manifest). A legacy file that cannot be deleted is logged and left where it
     * is: it stops deciding anything the moment appdata holds a manifest, this import never runs again,
     * and the doctor reports the leftover so it can be removed by hand.
     *
     * Nothing is imported over a manifest that is already in appdata (the caller checks that), and a
     * legacy file with unusable content is still taken over and removed - what it holds is logged, but a
     * writable copy of the review decisions is not something to keep around.
     *
     * @return array{json:string,etag:?string,error:string}|null null when there is nothing to import
     */
    private function importLegacy(): ?array
    {
        // checked again under the lock: a manifest that arrived in appdata while this import was waiting
        // for it is the newer one, and the old file is never copied over it
        if ($this->currentFile() !== null || !$this->legacyExists()) {
            return null;
        }
        $user = $this->storageUser();
        $path = $user . '/' . self::LEGACY_DIR . '/' . self::FILE;
        try {
            $json = (string)($this->files->read($user, self::LEGACY_DIR, self::FILE)['content'] ?? '');
        } catch (\Throwable $e) {
            $this->logger->warning('pages: the review manifest at the old location could not be read', [
                'path' => $path,
                'exception' => $e,
            ]);

            return null;
        }

        if (self::decode($json) === null) {
            $this->logger->warning('pages: the review manifest at the old location is not a usable manifest', [
                'path' => $path,
            ]);
        }
        try {
            $this->folder()->newFile(self::FILE, $json);
        } catch (\Throwable $e) {
            // nothing was imported, so nothing is deleted either: the old file stays the only copy
            $this->logger->warning('pages: the review manifest could not be imported into appdata', [
                'path' => $path,
                'exception' => $e,
            ]);

            return null;
        }

        try {
            $this->files->delete($user, self::LEGACY_DIR, self::FILE);
        } catch (\Throwable $e) {
            // appdata holds the manifest now and decides from here on; the leftover is inert, and a
            // cleanup must not fail the request that happened to trigger the import
            $this->logger->warning('pages: the review manifest was imported, but the copy at the old location could not be deleted', [
                'path' => $path,
                'exception' => $e,
            ]);
        }

        return $this->load();
    }

    /**
     * Writes the document, and answers whether the file was still the one that was read. A `false` means
     * it was not (there is something to compare and it differs, or a file appeared where none was), and
     * the caller answers that by re-reading and applying the change again.
     *
     * Two comparisons, for two different failures. The ETag is Nextcloud's own "this file changed"
     * signal and catches the ordinary case - an administrator restoring a backup, a second installation
     * writing to the same storage. The content comparison catches what the ETag cannot: on a local
     * storage the ETag follows the mtime in whole seconds (measured on this instance: two writes within
     * the same second keep the ETag, whatever their size), so a file replaced twice in one second looks
     * unchanged to it. Both are cheap here - the document was read a moment ago and is a few kilobytes.
     *
     * @param array<string,mixed> $manifest
     * @param array{json:string,etag:?string,error:string} $loaded the document the change was computed from
     * @throws \InvalidArgumentException when the document cannot be encoded (see encode())
     */
    private function write(array $manifest, array $loaded): bool
    {
        $json = $this->encode($manifest);
        $file = $this->currentFile();
        if ($file === null) {
            if ($loaded['etag'] !== null) {
                // it was there when it was read and is gone now: the change was meant for another document
                return false;
            }
            $this->folder()->newFile(self::FILE, $json);

            return true;
        }
        if ($loaded['etag'] === null) {
            // created behind our back: there is nothing to compare, and the change was meant for a
            // document that did not exist
            return false;
        }
        try {
            $current = $file->getContent();
        } catch (NotFoundException) {
            // it vanished between the two calls: the change was meant for a document that is gone
            return false;
        }
        if ($current !== $loaded['json'] || ($loaded['etag'] !== '' && $file->getETag() !== $loaded['etag'])) {
            return false;
        }
        $file->putContent($json);

        return true;
    }

    /**
     * The exclusive lock for a whole read-modify-write cycle. A writer that is already running turns into
     * the app's own conflict answer instead of an error page: the caller may simply try again.
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

    /**
     * Releases the lock. Deliberately never called on a path where the lock was not acquired: the
     * provider keeps one lock row per key, so releasing a lock this process does not hold would hand the
     * manifest to a second writer.
     */
    private function release(): void
    {
        $this->locking->releaseLock(self::LOCK_KEY, ILockingProvider::LOCK_EXCLUSIVE);
    }

    /**
     * The user whose files hold both the site and, at the old location, the manifest. '' while no site
     * storage user is configured: there is then no legacy file to import.
     */
    private function storageUser(): string
    {
        $site = $this->registry->getSite();

        return trim((string)($site['nc_user'] ?? ''));
    }

    /**
     * The database caches follow the manifest, which is what makes them a cache: `pages_articles`
     * carries the state and the review trail - the queue, the counts and the admin panel are read from
     * it - and `pages_published` carries the published list.
     *
     * The whole manifest is compared, not just what the caller changed: a row that drifted (a
     * hand-edited or restored manifest, a row written before the manifest existed) is repaired by the
     * next review action. Only entries that really differ are written, so a submit does not rewrite
     * every row of the table, and the moment a row was published is never moved by a re-sync.
     *
     * @param array<string,mixed> $manifest the document as it was just written
     */
    private function syncCaches(array $manifest): void
    {
        $this->syncArticles(is_array($manifest['entries'] ?? null) ? $manifest['entries'] : []);
        $this->syncPublished(is_array($manifest['published'] ?? null) ? $manifest['published'] : []);
    }

    /** @param array<string,array<string,mixed>> $entries */
    private function syncArticles(array $entries): void
    {
        if ($entries === []) {
            return;
        }
        // one query for the whole table: a row is only needed to tell whether anything has to change
        $rows = [];
        foreach ($this->articles->findAll() as $row) {
            $rows[(string)$row->getPath()] = $row;
        }
        foreach ($entries as $path => $entry) {
            $row = $rows['/' . $path] ?? null;
            if ($row === null || !self::articleDiffers($row, $entry)) {
                continue;
            }
            // 0 and '' mean "keep what the row has", exactly like the mapper's partial write intends:
            // that is what lets an edit keep the moment the article was submitted
            $this->articles->saveState(
                (int)$row->getId(),
                (string)$entry['state'],
                (string)$entry['note'],
                (int)$entry['submittedAt'],
                (int)$entry['publishedAt'],
                (string)$entry['revision'],
            );
        }
    }

    /**
     * A row that an article entry describes differently: the columns the mapper always writes are
     * compared directly, the ones it only writes when they carry a value only when they do.
     *
     * @param array<string,mixed> $entry
     */
    private static function articleDiffers(Article $row, array $entry): bool
    {
        return (string)$row->getState() !== (string)$entry['state']
            || (string)($row->getReviewNote() ?? '') !== (string)$entry['note']
            || ((int)$entry['submittedAt'] > 0 && (int)$row->getSubmittedAt() !== (int)$entry['submittedAt'])
            || ((int)$entry['publishedAt'] > 0 && (int)$row->getPublishedAt() !== (int)$entry['publishedAt'])
            || ((string)$entry['revision'] !== '' && (string)$row->getPublishedRevision() !== (string)$entry['revision']);
    }

    /**
     * The published table mirrors the manifest's published map; a path the manifest no longer lists (a
     * deletion a publish carried out) is forgotten here too.
     *
     * @param array<string,array<string,mixed>> $entries
     */
    private function syncPublished(array $entries): void
    {
        $cached = $this->published->allByPath();
        foreach ($entries as $path => $entry) {
            $row = $cached[$path] ?? null;
            if ($row !== null
                && (string)$row->getOwnerUid() === (string)$entry['owner']
                && (string)$row->getRevision() === (string)$entry['revision']) {
                continue;
            }
            $this->published->record([$path => (string)$entry['owner']], (string)$entry['revision']);
        }

        $gone = [];
        foreach (array_keys($cached) as $path) {
            if (!isset($entries[$path])) {
                $gone[] = $path;
            }
        }
        if ($gone !== []) {
            $this->published->forget($gone);
        }
    }
}
