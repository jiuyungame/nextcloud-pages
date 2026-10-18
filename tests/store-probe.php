<?php

/**
 * The review manifest's own logic (OCA\Pages\Service\ReviewStore), checked against in-memory fakes:
 * the appdata storage, the lock around a write cycle, ETag conflicts and retries, the one-time import
 * from the manifest's old location in a user's files, encode/decode round trips, the exact JSON shape,
 * corrupt or unreadable files, and the cache mirroring. Nothing here touches a live instance, so it runs
 * wherever the app's PHP is available.
 *
 * Run from the app root:  snap run --shell nextcloud.occ -c "php tests/store-probe.php"
 *
 * The fakes below deliberately shadow the real collaborators (same FQCNs), which is why this file must
 * never be loaded inside the app: it exists to test the store's own logic, not Nextcloud's storage.
 */

declare(strict_types=1);

namespace OCP\Files {
    class NotFoundException extends \Exception
    {
    }

    class NotPermittedException extends \Exception
    {
    }

    /** The app's own storage under appdata, as the store uses it. */
    interface IAppData
    {
        public function getFolder(string $name): \OCP\Files\SimpleFS\ISimpleFolder;

        public function newFolder(string $name): \OCP\Files\SimpleFS\ISimpleFolder;
    }
}

namespace OCP\Files\SimpleFS {
    interface ISimpleFolder
    {
        public function getName(): string;

        public function fileExists(string $name): bool;

        public function getFile(string $name): ISimpleFile;

        /** @param string|resource|null $content */
        public function newFile(string $name, $content = null): ISimpleFile;
    }

    interface ISimpleFile
    {
        public function getContent(): string;

        /** @param string|resource $data */
        public function putContent($data): void;

        public function getETag(): string;
    }
}

namespace OCP\Lock {
    class LockedException extends \Exception
    {
    }

    interface ILockingProvider
    {
        public const LOCK_SHARED = 1;
        public const LOCK_EXCLUSIVE = 2;

        public function acquireLock(string $path, int $type, ?string $readablePath = null): void;

        public function releaseLock(string $path, int $type): void;
    }
}

namespace OCP {
    interface IL10N
    {
        /** @param list<mixed> $params */
        public function t(string $text, array $params = []): string;
    }

    interface IConfig
    {
        public function getSystemValueString(string $key, string $default = ''): string;
    }
}

namespace Psr\Log {
    interface LoggerInterface
    {
        public function debug(string|\Stringable $message, array $context = []): void;

        public function warning(string|\Stringable $message, array $context = []): void;
    }
}

namespace OCA\Pages\Db {
    class Article
    {
        public const STATE_INTERNAL = 'internal';
        public const STATE_REQUESTED = 'requested';
        public const STATE_EXTERNAL = 'external';
        public const INTENT_ONLINE = 'online';
        public const INTENT_OFFLINE = 'offline';
        public const STATES = [
            self::STATE_INTERNAL,
            self::STATE_REQUESTED,
            self::STATE_EXTERNAL,
        ];
        public const INTENTS = [
            self::INTENT_ONLINE,
            self::INTENT_OFFLINE,
        ];

        public function __construct(
            public string $path = '',
            public string $state = self::STATE_INTERNAL,
            public ?string $note = null,
            public int $submittedAt = 0,
            public int $publishedAt = 0,
            public string $revision = '',
        ) {
        }

        public function getId(): int
        {
            return crc32($this->path);
        }

        public function getPath(): string
        {
            return $this->path;
        }

        public function getState(): string
        {
            return $this->state;
        }

        public function getReviewNote(): ?string
        {
            return $this->note;
        }

        public function getSubmittedAt(): int
        {
            return $this->submittedAt;
        }

        public function getPublishedAt(): int
        {
            return $this->publishedAt;
        }

        public function getPublishedRevision(): string
        {
            return $this->revision;
        }
    }

    class Published
    {
        public function __construct(
            public string $path = '',
            public string $owner = '',
            public string $revision = '',
            public int $publishedAt = 0,
        ) {
        }

        public function getPath(): string
        {
            return $this->path;
        }

        public function getOwnerUid(): string
        {
            return $this->owner;
        }

        public function getRevision(): string
        {
            return $this->revision;
        }

        public function getPublishedAt(): int
        {
            return $this->publishedAt;
        }
    }

    /** In-memory stand-in for the article cache. */
    class ArticleMapper
    {
        /** @var array<string,Article> */
        public array $rows = [];
        /** @var list<array<string,mixed>> */
        public array $calls = [];

        public function findAll(): array
        {
            return array_values($this->rows);
        }

        public function findByPath(string $path): ?Article
        {
            return $this->rows[$path] ?? null;
        }

        public function saveState(
            int $id,
            string $state,
            string $note = '',
            int $submittedAt = 0,
            int $publishedAt = 0,
            string $publishedRevision = '',
        ): Article {
            foreach ($this->rows as $row) {
                if ($row->getId() !== $id) {
                    continue;
                }
                $this->calls[] = ['path' => $row->path, 'state' => $state, 'note' => $note, 'submittedAt' => $submittedAt, 'publishedAt' => $publishedAt, 'revision' => $publishedRevision];
                $row->state = $state;
                $row->note = $note === '' ? null : $note;
                if ($submittedAt > 0) {
                    $row->submittedAt = $submittedAt;
                }
                if ($publishedAt > 0) {
                    $row->publishedAt = $publishedAt;
                }
                if ($publishedRevision !== '') {
                    $row->revision = $publishedRevision;
                }

                return $row;
            }
            throw new \InvalidArgumentException('no row ' . $id);
        }

        public function deleteById(int $id): void
        {
            foreach ($this->rows as $path => $row) {
                if ($row->getId() === $id) {
                    $this->calls[] = ['op' => 'delete', 'path' => $path];
                    unset($this->rows[$path]);

                    return;
                }
            }
        }
    }

    /** In-memory stand-in for the published cache. */
    class PublishedMapper
    {
        /** @var array<string,Published> */
        public array $rows = [];
        /** @var list<array<string,mixed>> */
        public array $calls = [];

        /** @return array<string,Published> */
        public function allByPath(): array
        {
            return $this->rows;
        }

        /** @param array<string,string> $paths */
        public function record(array $paths, string $revision): int
        {
            $written = 0;
            foreach ($paths as $path => $owner) {
                $path = ltrim(trim((string)$path), '/');
                if ($path === '') {
                    continue;
                }
                $this->calls[] = ['op' => 'record', 'path' => $path, 'revision' => $revision];
                $existing = $this->rows[$path] ?? null;
                if ($existing !== null && $existing->revision === $revision && $existing->owner === trim((string)$owner)) {
                    continue;
                }
                $this->rows[$path] = new Published($path, trim((string)$owner), $revision, time());
                $written++;
            }

            return $written;
        }

        /** @param list<string> $paths @return list<string> */
        public function forget(array $paths): array
        {
            $removed = [];
            foreach ($paths as $path) {
                $path = ltrim(trim((string)$path), '/');
                if (!isset($this->rows[$path])) {
                    continue;
                }
                $this->calls[] = ['op' => 'forget', 'path' => $path];
                unset($this->rows[$path]);
                $removed[] = $path;
            }

            return $removed;
        }

        public function countAll(): int
        {
            return count($this->rows);
        }
    }
}

namespace OCA\Pages\Service {
    use OCP\Files\NotFoundException;
    use OCP\Files\NotPermittedException;

    /**
     * In-memory stand-in for the Files API, which the store still uses for one thing: reading and
     * deleting the manifest at its old location, in the site storage user's own files.
     */
    class FileService
    {
        public bool $legacyExists = false;
        public string $legacyContent = '';
        public bool $readFails = false;
        public bool $deleteFails = false;
        /** @var list<string> */
        public array $reads = [];
        /** @var list<string> */
        public array $deletes = [];

        /** @return array<string,mixed> */
        public function read(string $userId, string $ncPath, string $relPath): array
        {
            $this->reads[] = $userId . ':' . $ncPath . '/' . $relPath;
            if ($this->readFails) {
                throw new NotPermittedException('the legacy manifest cannot be read');
            }
            if (!$this->legacyExists) {
                throw new NotFoundException('no legacy file');
            }

            return ['content' => $this->legacyContent, 'etag' => 'legacy-etag'];
        }

        public function fileExists(string $userId, string $ncPath, string $relPath): bool
        {
            return $this->legacyExists
                && $ncPath === ReviewStore::LEGACY_DIR
                && $relPath === ReviewStore::FILE;
        }

        public function delete(string $userId, string $ncPath, string $relPath): void
        {
            if ($this->deleteFails) {
                throw new NotPermittedException('the legacy manifest cannot be deleted');
            }
            $this->deletes[] = $userId . ':' . $ncPath . '/' . $relPath;
            $this->legacyExists = false;
        }
    }

    class SiteRegistry
    {
        public function __construct(public ?array $site = ['nc_user' => 'siteuser', 'nc_path' => 'Pages/site'])
        {
        }

        public function getSite(): ?array
        {
            return $this->site;
        }
    }

    class L10N implements \OCP\IL10N
    {
        public function t(string $text, array $params = []): string
        {
            return $params === [] ? $text : vsprintf($text, $params);
        }
    }
}

namespace Probe {
    use OCA\Pages\Service\ReviewStore;
    use OCP\Files\NotFoundException;
    use OCP\Files\NotPermittedException;
    use OCP\Files\SimpleFS\ISimpleFile;
    use OCP\Files\SimpleFS\ISimpleFolder;
    use OCP\Lock\ILockingProvider;
    use OCP\Lock\LockedException;

    /** The app's appdata folder, in memory: one manifest file, with a real ETag. */
    final class FakeAppData implements \OCP\Files\IAppData
    {
        public ?string $content = null;
        public string $etag = '';
        public string $folderName = 'pages';
        public string $readError = '';
        /** How many writes have to find the file replaced behind them (see FakeFile::getContent()). */
        public int $conflicts = 0;
        /**
         * A replacement that keeps the ETag - what a local storage does for two writes inside the same
         * second, where the ETag follows the mtime.
         */
        public bool $replacementKeepsEtag = false;
        /** @var list<string> */
        public array $writes = [];
        /** @var list<string> */
        public array $folderRequests = [];
        /** Armed by a read while a conflict is pending: the next file open is where the other writer wins. */
        public bool $replaceOnNextOpen = false;
        private int $generation = 0;

        public function getFolder(string $name): ISimpleFolder
        {
            $this->folderRequests[] = $name;
            // only the app's own root is asked for; a folder named like the app would nest the manifest
            if ($name !== '/') {
                throw new NotFoundException('no folder ' . $name);
            }

            return new FakeFolder($this);
        }

        public function newFolder(string $name): ISimpleFolder
        {
            throw new \RuntimeException('the probe never creates a folder: ' . $name);
        }

        /** The document another writer left: what a write has to notice before it overwrites it. */
        public function replaceByOtherWriter(): void
        {
            $other = json_decode($this->content ?? '{}', true);
            $other['entries']['tmp/other/two.md'] = [
                'state' => 'requested', 'owner' => 'other', 'submittedAt' => 5,
                'decidedBy' => '', 'intent' => 'online', 'note' => '', 'publishedAt' => 0, 'revision' => '',
            ];
            $this->content = (string)json_encode($other);
            if (!$this->replacementKeepsEtag) {
                $this->etag = 'etag-other-' . (++$this->generation);
            }
        }

        public function create(string $content): void
        {
            $this->content = $content;
            $this->etag = 'etag-' . (++$this->generation);
            $this->writes[] = $content;
        }
    }

    final class FakeFolder implements ISimpleFolder
    {
        public function __construct(private FakeAppData $appData)
        {
        }

        public function getName(): string
        {
            return $this->appData->folderName;
        }

        public function fileExists(string $name): bool
        {
            return $name === ReviewStore::FILE && $this->appData->content !== null;
        }

        public function getFile(string $name): ISimpleFile
        {
            if ($name !== ReviewStore::FILE) {
                throw new NotFoundException('no file ' . $name);
            }
            if ($this->appData->content === null) {
                throw new NotFoundException('the manifest is not there');
            }
            if ($this->appData->replaceOnNextOpen) {
                // the other writer got in between the read and this open of the file
                $this->appData->replaceOnNextOpen = false;
                $this->appData->conflicts = max(0, $this->appData->conflicts - 1);
                $this->appData->replaceByOtherWriter();
            }

            return new FakeFile($this->appData);
        }

        public function newFile(string $name, $content = null): ISimpleFile
        {
            if ($name !== ReviewStore::FILE) {
                throw new NotPermittedException('unexpected file ' . $name);
            }
            $this->appData->create((string)$content);

            return new FakeFile($this->appData);
        }
    }

    final class FakeFile implements ISimpleFile
    {
        public function __construct(private FakeAppData $appData)
        {
        }

        public function getContent(): string
        {
            if ($this->appData->readError !== '') {
                throw new NotPermittedException($this->appData->readError);
            }
            if ($this->appData->content === null) {
                throw new NotFoundException('the manifest is not there');
            }
            if ($this->appData->conflicts > 0) {
                // a read while a conflict is pending: the next open of the file finds it replaced
                $this->appData->replaceOnNextOpen = true;
            }

            return $this->appData->content;
        }

        public function putContent($data): void
        {
            $this->appData->create((string)$data);
        }

        public function getETag(): string
        {
            return $this->appData->etag;
        }
    }

    /** Locking provider: one lock per key, as the real one has, and it is not recursive either. */
    final class FakeLocking implements ILockingProvider
    {
        /** @var array<string,int> */
        private array $held = [];
        /** @var list<string> */
        public array $acquired = [];
        public int $released = 0;
        public bool $busy = false;

        public function acquireLock(string $path, int $type, ?string $readablePath = null): void
        {
            if ($this->busy || isset($this->held[$path])) {
                throw new LockedException($path);
            }
            $this->held[$path] = $type;
            $this->acquired[] = $path;
        }

        public function releaseLock(string $path, int $type): void
        {
            if (!isset($this->held[$path])) {
                // the real provider would clear the lock row - a row another process may hold. Releasing
                // what was never acquired is the bug this throws on.
                throw new \RuntimeException('released a lock that was not acquired: ' . $path);
            }
            unset($this->held[$path]);
            $this->released++;
        }

        /** @return list<string> */
        public function held(): array
        {
            return array_keys($this->held);
        }
    }

    final class FakeConfig implements \OCP\IConfig
    {
        public function getSystemValueString(string $key, string $default = ''): string
        {
            return $key === 'instanceid' ? 'probe' : $default;
        }
    }

    final class FakeLogger implements \Psr\Log\LoggerInterface
    {
        /** @var list<array{level:string,message:string}> */
        public array $lines = [];

        public function debug(string|\Stringable $message, array $context = []): void
        {
            $this->lines[] = ['level' => 'debug', 'message' => (string)$message];
        }

        public function warning(string|\Stringable $message, array $context = []): void
        {
            $this->lines[] = ['level' => 'warning', 'message' => (string)$message];
        }

        /** @return list<string> */
        public function warnings(): array
        {
            return array_column(array_filter($this->lines, static fn (array $line): bool => $line['level'] === 'warning'), 'message');
        }
    }
}

namespace {
    use OCA\Pages\Db\Article;
    use OCA\Pages\Db\ArticleMapper;
    use OCA\Pages\Db\Published;
    use OCA\Pages\Db\PublishedMapper;
    use OCA\Pages\Service\AuthorContentReset;
    use OCA\Pages\Service\ConflictException;
    use OCA\Pages\Service\FileService;
    use OCA\Pages\Service\L10N;
    use OCA\Pages\Service\ReviewStore;
    use OCA\Pages\Service\SiteRegistry;
    use OCP\Files\NotPermittedException;
    use Probe\FakeAppData;
    use Probe\FakeConfig;
    use Probe\FakeLocking;
    use Probe\FakeLogger;

    require_once __DIR__ . '/../lib/Service/ConflictException.php';
    require_once __DIR__ . '/../lib/Service/ReviewStore.php';

    $pass = 0;
    $fail = 0;
    $check = function (string $what, bool $ok, string $detail = '') use (&$pass, &$fail): void {
        if ($ok) {
            $pass++;
            echo "  ok  $what\n";

            return;
        }
        $fail++;
        echo "  FAIL $what" . ($detail !== '' ? "  [$detail]" : '') . "\n";
    };

    /**
     * One store with fresh fakes.
     *
     * @return array{store:ReviewStore,appData:FakeAppData,locking:FakeLocking,files:FileService,articles:ArticleMapper,published:PublishedMapper,logger:FakeLogger}
     */
    $build = function (?SiteRegistry $registry = null): array {
        $appData = new FakeAppData();
        $locking = new FakeLocking();
        $files = new FileService();
        $articles = new ArticleMapper();
        $published = new PublishedMapper();
        $logger = new FakeLogger();
        $store = new ReviewStore(
            $appData,
            $locking,
            $files,
            $registry ?? new SiteRegistry(),
            new FakeConfig(),
            $articles,
            $published,
            $logger,
            new L10N(),
        );

        return compact('store', 'appData', 'locking', 'files', 'articles', 'published', 'logger');
    };

    $document = static function (array $articles, array $published = []): string {
        return (string)json_encode(['version' => 2, 'updatedAt' => 7, 'entries' => $articles, 'published' => $published]);
    };

    echo "1. a missing manifest is the empty manifest\n";
    ['store' => $store, 'appData' => $appData, 'locking' => $locking] = $build();
    $manifest = $store->read();
    $check('read() answers the documented shape', $manifest === ['version' => 2, 'updatedAt' => 0, 'entries' => [], 'published' => []]);
    $check('exists() is false', $store->exists() === false);
    $check('state() of an unknown article is internal', $store->state('/tmp/a/one.md') === Article::STATE_INTERNAL);
    $check('entry() of an unknown article is []', $store->entry('tmp/a/one.md') === []);
    $check('published() is empty', $store->published() === []);
    $check('the app\'s own appdata root is the only folder ever asked for', array_unique($appData->folderRequests) === ['/']);
    $check('location() names the appdata path', $store->location() === 'appdata_probe/pages/review.json');
    $check('a read takes no lock', $locking->acquired === [] && $locking->released === 0);
    $check('a write works without a site storage user (the manifest is in nobody\'s files)', (function () use ($build): bool {
        ['store' => $store, 'appData' => $appData] = $build(new SiteRegistry(null));
        $store->setState(['tmp/a/one.md'], 'requested', ['intent' => 'online']);
        $decoded = json_decode($appData->content ?? '', true);

        return ($decoded['entries']['tmp/a/one.md']['state'] ?? '') === 'requested'
            && ($decoded['entries']['tmp/a/one.md']['intent'] ?? '') === 'online';
    })());

    echo "2. setState writes the manifest and syncs the cache\n";
    ['store' => $store, 'appData' => $appData, 'locking' => $locking, 'articles' => $articles] = $build();
    $articles->rows['/tmp/a/one.md'] = new Article('/tmp/a/one.md');
    $articles->rows['/index.md'] = new Article('/index.md', Article::STATE_EXTERNAL);
    $store->setState(['/tmp/a/one.md'], Article::STATE_REQUESTED, ['owner' => 'a', 'submittedAt' => 111, 'note' => '', 'decidedBy' => '', 'intent' => 'online']);
    $raw = $appData->content ?? '';
    $decoded = json_decode($raw, true);
    $check('the file was created', $raw !== '');
    $check('the document has version/updatedAt/entries/published', is_array($decoded) && array_keys($decoded) === ['version', 'updatedAt', 'entries', 'published']);
    $check('version is 2 and updatedAt is a stamp', ($decoded['version'] ?? null) === 2 && ($decoded['updatedAt'] ?? 0) > 0);
    $entry = $decoded['entries']['tmp/a/one.md'] ?? null;
    $check('the entry carries the documented fields', is_array($entry) && array_keys($entry) === ['state', 'owner', 'submittedAt', 'decidedBy', 'intent', 'note', 'publishedAt', 'revision']);
    $check('the entry holds the new state, the owner and the intent', ($entry['state'] ?? '') === 'requested'
        && ($entry['owner'] ?? '') === 'a' && ($entry['intent'] ?? '') === 'online');
    $check('the entry holds the request moment', ($entry['submittedAt'] ?? 0) === 111);
    $check('the file keeps a trailing newline', str_ends_with($raw, "\n"));
    $check('the file is indented with two spaces', str_contains($raw, "\n  \"updatedAt\"") && !str_contains($raw, "\n    \"updatedAt\""));
    $check('the article cache got the state', $articles->calls === [['path' => '/tmp/a/one.md', 'state' => 'requested', 'note' => '', 'submittedAt' => 111, 'publishedAt' => 0, 'revision' => '']]);
    $check('the state is visible through the accessors', $store->state('/tmp/a/one.md') === 'requested');
    $check('entryOf/isPublished work off a manifest', ReviewStore::stateOf($manifest, 'tmp/a/one.md') === Article::STATE_INTERNAL
        && ReviewStore::isPublished($manifest, '/index.md') === false);
    $check('the write held the app\'s lock for its key and released it', $locking->acquired === ['pages/review.json']
        && $locking->held() === [] && $locking->released === 1);

    echo "3. a second write only touches what differs\n";
    $before = count($articles->calls);
    $store->setState(['tmp/a/one.md'], 'external', ['note' => '', 'decidedBy' => 'bob']);
    $check('an approval is written once', count($articles->calls) === $before + 1 && $articles->calls[$before]['state'] === 'external');
    $fileWrites = count($appData->writes);
    $store->setState(['tmp/a/one.md'], 'external', ['note' => '', 'decidedBy' => 'bob']);
    $check('a no-op decision still writes the manifest (brings the caches in step)', count($appData->writes) === $fileWrites + 1);
    $check('but the unchanged row is not written again', count($articles->calls) === $before + 1);
    $check('the entry keeps the request moment', ($store->entry('tmp/a/one.md')['submittedAt'] ?? 0) === 111);
    $check('the reviewer is recorded', ($store->entry('tmp/a/one.md')['decidedBy'] ?? '') === 'bob');
    $check('a state other than requested clears the intent', ($store->entry('tmp/a/one.md')['intent'] ?? 'x') === '');

    echo "4. record()/forget() and the published cache\n";
    ['store' => $store, 'appData' => $appData, 'published' => $published] = $build();
    $published->rows['gone.md'] = new Published('gone.md', 'a', 'old', time());
    $recorded = $store->record(['tmp/a/one.md' => 'a', '/index.md' => '', 'assets/site.css' => 'a'], 'rev1');
    $check('record() counts the new entries', $recorded === 3);
    $check('the paths are normalised and sorted (no leading slash)', array_keys($store->published()) === ['assets/site.css', 'index.md', 'tmp/a/one.md']);
    $check('record() answers 0 when nothing changed', $store->record(['tmp/a/one.md' => 'a'], 'rev1') === 0);
    $check('a new revision is a change', $store->record(['tmp/a/one.md' => 'a'], 'rev2') === 1);
    $check('the published cache mirrors the manifest', array_keys($published->rows) === ['assets/site.css', 'index.md', 'tmp/a/one.md']);
    $check('a cache row the manifest does not list was forgotten', !isset($published->rows['gone.md']));
    $forgotten = $store->forget(['tmp/a/one.md', 'never-there.md']);
    $check('forget() answers only the paths that were listed', $forgotten === ['tmp/a/one.md']);
    $check('the published cache forgot it too', !isset($published->rows['tmp/a/one.md']));
    $check('the manifest no longer lists it', !ReviewStore::isPublished($store->read(), 'tmp/a/one.md'));
    $check('forget() of an empty list writes nothing', (function () use ($store, $appData): bool {
        $n = count($appData->writes);
        $store->forget([]);

        return count($appData->writes) === $n;
    })());

    echo "5. a replacement behind our back re-reads and re-applies the change\n";
    ['store' => $store, 'appData' => $appData, 'locking' => $locking, 'articles' => $articles, 'logger' => $logger] = $build();
    $store->setState(['tmp/a/one.md'], 'requested', ['owner' => 'a', 'submittedAt' => 111, 'intent' => 'online']);
    $appData->conflicts = 1;
    $store->setState(['tmp/a/one.md'], 'external', ['note' => '', 'decidedBy' => 'bob']);
    $check('the retry applied the change', $store->state('tmp/a/one.md') === 'external');
    $check('and it kept what the other writer had written', $store->state('tmp/other/two.md') === 'requested');
    $check('the request moment survived the retry', ($store->entry('tmp/a/one.md')['submittedAt'] ?? 0) === 111);
    $check('the lock was taken for each attempt and released', $locking->acquired === ['pages/review.json', 'pages/review.json']
        && $locking->held() === [] && $locking->released === 2);
    $check('nothing was logged about it', $logger->lines === []);
    // On a local storage the ETag follows the mtime in whole seconds, so a file replaced twice inside one
    // second keeps its ETag: the content comparison has to catch that one (see ReviewStore::write()).
    $appData->replacementKeepsEtag = true;
    $appData->conflicts = 1;
    $store->setState(['tmp/a/one.md'], 'internal', ['note' => 'no', 'decidedBy' => 'bob']);
    $check('a replacement that keeps the ETag is noticed too', $store->state('tmp/a/one.md') === 'internal'
        && $store->state('tmp/other/two.md') === 'requested');

    echo "6. a manifest that keeps changing gives up with a message\n";
    ['store' => $store, 'appData' => $appData, 'locking' => $locking] = $build();
    $store->setState(['tmp/a/one.md'], 'requested', ['owner' => 'a']);
    $appData->conflicts = 99;
    try {
        $store->setState(['tmp/a/one.md'], 'external');
        $check('a permanent conflict throws', false);
    } catch (ConflictException $e) {
        $check('a permanent conflict throws a ConflictException', str_contains($e->getMessage(), '3 times in a row'));
    }
    $check('the lock is released even then', $locking->held() === []);
    $check('the other writer\'s document is untouched', $store->state('tmp/other/two.md') === 'requested');

    echo "7. missing / empty / corrupt / unreadable files\n";
    ['store' => $store, 'appData' => $appData, 'locking' => $locking] = $build();
    $appData->create('');
    $check('an empty file reads as the empty manifest', $store->read()['entries'] === []);
    $appData->create('{not json');
    $check('a corrupt file reads as the empty manifest', $store->read() === ['version' => 2, 'updatedAt' => 0, 'entries' => [], 'published' => []]);
    $check('a corrupt file is reported as existing', $store->exists() === true);
    $store->setState(['tmp/a/one.md'], 'requested', ['owner' => 'a']);
    $check('the next write replaces it with a valid document', json_decode($appData->content ?? '', true) !== null);
    $appData->create('[]');
    $check('a JSON array reads as the empty manifest', $store->read()['entries'] === []);
    $store->setState(['tmp/a/one.md'], 'requested', ['owner' => 'a']);
    $check('a JSON array is replaced by a document as well', json_decode($appData->content ?? '', true) !== null);
    $appData->readError = 'permission denied';
    $check('an unreadable file still answers read()', $store->read()['entries'] === []);
    $check('but a write is refused instead of wiping it', (function () use ($store): bool {
        try {
            $store->setState(['tmp/a/one.md'], 'external');
        } catch (NotPermittedException $e) {
            return str_contains($e->getMessage(), 'permission denied');
        }

        return false;
    })());
    $check('and the lock is released after that refusal', $locking->held() === []);

    echo "8. a hand-edited file cannot break the readers\n";
    ['store' => $store, 'appData' => $appData] = $build();
    $appData->create((string)json_encode([
        'version' => 2,
        'entries' => [
            '../etc/passwd' => ['state' => 'external'],
            '/tmp/a/one.md' => ['state' => 'External', 'submittedAt' => 'nonsense', 'note' => ['oops'], 'owner' => ' a ', 'intent' => 'bogus'],
            'tmp/a/two.md' => 'not an entry',
            'tmp/a/three.md' => ['state' => 'requested', 'decidedBy' => 7, 'intent' => 'online'],
        ],
        'published' => ['tmp/a/one.md' => ['owner' => 'a', 'revision' => 'r1'], 'bad/../path.md' => ['owner' => 'a']],
    ]));
    $manifest = $store->read();
    $check('a path with .. is dropped', !isset($manifest['entries']['../etc/passwd']) && !isset($manifest['published']['bad/../path.md']));
    $check('a leading slash is normalised away', isset($manifest['entries']['tmp/a/one.md']));
    $check('an unknown state reads as internal', $store->state('tmp/a/one.md') === Article::STATE_INTERNAL);
    $check('an unknown intent on a non-requested entry reads as empty', ($store->entry('tmp/a/one.md')['intent'] ?? 'x') === '');
    $check('a non-numeric timestamp reads as 0', ($store->entry('tmp/a/one.md')['submittedAt'] ?? -1) === 0);
    $check('a non-string note reads as empty', ($store->entry('tmp/a/one.md')['note'] ?? 'x') === '');
    $check('the owner is trimmed', ($store->entry('tmp/a/one.md')['owner'] ?? '') === 'a');
    $check('an entry that is not an object is dropped', !isset($manifest['entries']['tmp/a/two.md']));
    $check('a non-string decider reads as its scalar form', ($store->entry('tmp/a/three.md')['decidedBy'] ?? '') === '7');
    $check('a requested entry keeps its intent', ReviewStore::intentOf($manifest, 'tmp/a/three.md') === Article::INTENT_ONLINE);
    $check('isPublished answers for the normalised key', ReviewStore::isPublished($manifest, '/tmp/a/one.md') === true);

    echo "8b. a pre-v2 manifest (section \"articles\") still reads, and is rewritten as \"entries\"\n";
    ['store' => $store, 'appData' => $appData] = $build();
    $appData->create((string)json_encode([
        'version' => 1,
        'articles' => ['_posts/a/one.md' => ['state' => 'external', 'owner' => 'a']],
        'published' => ['_posts/a/one.md' => ['owner' => 'a', 'revision' => 'r1']],
    ]));
    $legacy = $store->read();
    $check('the legacy articles section reads as entries', ($legacy['entries']['_posts/a/one.md']['state'] ?? '') === 'external');
    $check('and the legacy published list is read as it is', ReviewStore::isPublished($legacy, '_posts/a/one.md') === true);
    $store->setState(['_posts/a/other.md'], 'requested', ['owner' => 'a', 'intent' => 'online']);
    $rewritten = json_decode($appData->content ?? '', true);
    $check('the next write stores the entries section only', isset($rewritten['entries']) && !isset($rewritten['articles']));
    $check('and keeps the entry the legacy file held', ($rewritten['entries']['_posts/a/one.md']['state'] ?? '') === 'external');

    echo "9. the manifest is sorted, so a diff shows the change\n";
    ['store' => $store, 'appData' => $appData] = $build();
    foreach (['tmp/a/z.md', 'tmp/a/a.md', 'tmp/a/m.md'] as $path) {
        $store->setState([$path], 'requested', ['owner' => 'a', 'intent' => 'online']);
    }
    $store->record(['zeta.md' => '', 'alpha.md' => ''], 'rev');
    $raw = $appData->content ?? '';
    $check('the articles map is sorted by key', strpos($raw, 'tmp/a/a.md') < strpos($raw, 'tmp/a/m.md')
        && strpos($raw, 'tmp/a/m.md') < strpos($raw, 'tmp/a/z.md'));
    $check('the published map is sorted by key', strpos($raw, 'alpha.md') < strpos($raw, 'zeta.md'));

    echo "10. the article cache is repaired on the next write\n";
    ['store' => $store, 'appData' => $appData, 'articles' => $articles] = $build();
    // a row from before the manifest existed: a state of its own, and stamps the entry will not carry
    $articles->rows['/tmp/a/one.md'] = new Article('/tmp/a/one.md', Article::STATE_EXTERNAL, 'old note', 5, 999, 'oldrev');
    $store->setState(['tmp/a/one.md'], 'requested', ['owner' => 'a', 'submittedAt' => 111, 'intent' => 'online']);
    $row = $articles->rows['/tmp/a/one.md'];
    $check('the drifted row was written back from the manifest', $row->state === 'requested' && $row->note === null);
    $check('the request moment came from the entry', $row->submittedAt === 111);
    $check('the publish stamps the entry does not carry are kept', $row->publishedAt === 999 && $row->revision === 'oldrev');
    $row->state = 'external';   // somebody edited the table behind the app's back
    $store->record(['index.md' => ''], 'rev9');
    $check('the next write repairs the drifted row too', $articles->rows['/tmp/a/one.md']->state === 'requested');
    $check('and the stamps are still not cleared', $articles->rows['/tmp/a/one.md']->publishedAt === 999);
    $writes = count($articles->calls);
    $store->setState(['tmp/a/one.md'], 'requested', ['owner' => 'a', 'submittedAt' => 111, 'intent' => 'online']);
    $check('an in-step row is never rewritten', count($articles->calls) === $writes);

    echo "11. the lock: one writer at a time, and never released unheld\n";
    ['store' => $store, 'appData' => $appData, 'locking' => $locking] = $build();
    $locking->busy = true;   // another request is writing right now
    try {
        $store->setState(['tmp/a/one.md'], 'requested');
        $check('a busy lock is refused', false);
    } catch (ConflictException $e) {
        $check('a busy lock answers the app\'s own conflict', str_contains($e->getMessage(), 'being written right now'));
    }
    $check('and nothing was written', $appData->content === null);
    $check('and no lock was taken or released', $locking->acquired === [] && $locking->released === 0 && $locking->held() === []);
    $locking->busy = false;
    $check('the same key is never held twice (the provider is not recursive)', (function () use ($store, $locking): bool {
        $store->setState(['tmp/a/one.md'], 'requested');
        $store->record(['index.md' => ''], 'rev');

        return $locking->held() === [] && count($locking->acquired) === 2;
    })());

    echo "12. the manifest at its old location is imported once\n";
    ['store' => $store, 'appData' => $appData, 'locking' => $locking, 'files' => $files, 'logger' => $logger] = $build();
    $files->legacyExists = true;
    $files->legacyContent = $document(['tmp/a/one.md' => [
        'state' => 'external', 'owner' => 'a', 'submittedAt' => 111, 'decidedBy' => 'bob',
        'intent' => '', 'note' => '', 'publishedAt' => 999, 'revision' => 'abc',
    ]], ['tmp/a/one.md' => ['owner' => 'a', 'revision' => 'abc', 'publishedAt' => 999]]);
    $check('read() takes the old document over', $store->state('tmp/a/one.md') === Article::STATE_EXTERNAL);
    $check('the old file was read once and deleted', $files->reads === ['siteuser:' . ReviewStore::LEGACY_DIR . '/' . ReviewStore::FILE]
        && $files->deletes === ['siteuser:' . ReviewStore::LEGACY_DIR . '/' . ReviewStore::FILE]
        && $files->legacyExists === false);
    $check('it is in appdata now, byte for byte', $appData->content === $files->legacyContent && $store->exists());
    $check('the import took the lock and released it', $locking->acquired === ['pages/review.json'] && $locking->held() === [] && $locking->released === 1);
    $check('nothing had to be logged', $logger->lines === []);
    $store->read();
    $check('a later read does not look at the old location again', count($files->reads) === 1);
    $check('a later write imports nothing either', (function () use ($store, $files): bool {
        $store->setState(['tmp/a/one.md'], 'requested');

        return count($files->reads) === 1;
    })());

    echo "13. the import never overwrites a manifest that is already there\n";
    ['store' => $store, 'appData' => $appData, 'files' => $files, 'logger' => $logger] = $build();
    $appData->create($document(['tmp/a/one.md' => ['state' => 'external', 'owner' => 'a']]));
    $files->legacyExists = true;
    $files->legacyContent = $document(['tmp/a/one.md' => ['state' => 'internal', 'owner' => 'a', 'note' => 'old']]);
    $check('appdata wins', $store->state('tmp/a/one.md') === Article::STATE_EXTERNAL);
    $check('the old file was neither read nor deleted', $files->reads === [] && $files->deletes === [] && $files->legacyExists === true);
    $check('and it is reported as a leftover', $store->legacyExists() === true);
    $check('and nothing was logged about it', $logger->lines === []);

    echo "14. a broken old copy is reported, not fatal\n";
    ['store' => $store, 'appData' => $appData, 'files' => $files, 'logger' => $logger] = $build();
    $files->legacyExists = true;
    $files->readFails = true;
    $check('an unreadable old copy leaves the empty manifest', $store->read()['entries'] === []);
    $check('and it is logged as a warning', count($logger->warnings()) === 1 && str_contains($logger->warnings()[0], 'could not be read'));
    $check('and the old file is left alone (it is the only copy)', $files->legacyExists === true && $files->deletes === []);
    ['store' => $store, 'appData' => $appData, 'files' => $files, 'logger' => $logger] = $build();
    $files->legacyExists = true;
    $files->legacyContent = '{not a manifest';
    $check('an unusable old copy reads as the empty manifest', $store->read()['entries'] === []);
    $check('but it still moves in (a writable record must not stay in a user\'s files)', $appData->content === '{not a manifest');
    $check('and it is deleted and logged', $files->legacyExists === false
        && count($logger->warnings()) === 1 && str_contains($logger->warnings()[0], 'not a usable manifest'));
    ['store' => $store, 'appData' => $appData, 'files' => $files, 'logger' => $logger] = $build();
    $files->legacyExists = true;
    $files->legacyContent = $document(['tmp/a/one.md' => ['state' => 'requested', 'owner' => 'a', 'intent' => 'online']]);
    $files->deleteFails = true;
    $check('a failed cleanup still imports', $store->state('tmp/a/one.md') === Article::STATE_REQUESTED);
    $check('the leftover is logged and stays', $files->legacyExists === true
        && count($logger->warnings()) === 1 && str_contains($logger->warnings()[0], 'could not be deleted'));
    $store->setState(['tmp/a/one.md'], 'external');
    $check('and appdata decides from then on', $store->state('tmp/a/one.md') === Article::STATE_EXTERNAL
        && count($files->reads) === 1);

    echo "15. what the file looks like after request → approve → publish\n";
    ['store' => $store, 'appData' => $appData] = $build();
    $store->setState(['tmp/alice/2026-05-01-hello.md'], 'requested', ['owner' => 'alice', 'submittedAt' => 1792040000, 'intent' => 'online']);
    $store->setState(['tmp/alice/2026-05-01-hello.md'], 'external', ['intent' => '', 'note' => '', 'decidedBy' => 'bob']);
    $store->record(['tmp/alice/2026-05-01-hello.md' => 'alice'], '8f2c1a9d');
    $store->setState(['tmp/alice/2026-05-01-hello.md'], 'external', ['intent' => '', 'publishedAt' => 1792041500, 'revision' => '8f2c1a9d']);
    echo $appData->content;
    $check('json() is what the file holds', $store->json() === $appData->content);
    $check('the published entry is external with the commit that carried it', ($store->entry('tmp/alice/2026-05-01-hello.md')['state'] ?? '') === 'external'
        && ($store->entry('tmp/alice/2026-05-01-hello.md')['revision'] ?? '') === '8f2c1a9d');

    echo "16. state + intent: the pair that says what the site has\n";
    ['store' => $store, 'appData' => $appData, 'articles' => $articles, 'published' => $published] = $build();
    $articles->rows['/tmp/a/one.md'] = new Article('/tmp/a/one.md', Article::STATE_EXTERNAL);
    $store->record(['tmp/a/one.md' => 'a'], 'rev1');
    $check('an article nobody asked anything of has no entry yet', $store->entry('tmp/a/one.md') === []);
    $store->setState(['tmp/a/one.md'], Article::STATE_REQUESTED, ['owner' => 'a', 'submittedAt' => 111, 'intent' => Article::INTENT_OFFLINE]);
    $entry = $store->entry('tmp/a/one.md');
    $check('the request is the state plus the intent', ($entry['state'] ?? '') === 'requested' && ($entry['intent'] ?? '') === 'offline');
    $check('a pending take-offline is still on the site (nothing is decided yet)', ReviewStore::onSite($store->read(), '/tmp/a/one.md') === true);
    $check('the intent is readable through the accessor', ReviewStore::intentOf($store->read(), '/tmp/a/one.md') === Article::INTENT_OFFLINE);
    $store->setState(['tmp/a/one.md'], Article::STATE_INTERNAL, ['intent' => '', 'decidedBy' => 'bob']);
    $check('an internal entry is not on the site', ReviewStore::onSite($store->read(), '/tmp/a/one.md') === false);
    $check('a decided entry carries no intent', ($store->entry('tmp/a/one.md')['intent'] ?? 'x') === '');
    $check('the decision is recorded', ($store->entry('tmp/a/one.md')['decidedBy'] ?? '') === 'bob');
    $store->setState(['tmp/a/one.md'], Article::STATE_REQUESTED, ['intent' => Article::INTENT_ONLINE, 'submittedAt' => 222]);
    $check('a pending online request is not on the site yet', ReviewStore::onSite($store->read(), '/tmp/a/one.md') === false);
    $store->setState(['tmp/a/one.md'], Article::STATE_EXTERNAL, ['intent' => '']);
    $check('an external entry is on the site', ReviewStore::onSite($store->read(), '/tmp/a/one.md') === true);
    // a hand-edited intent of an unknown value records no direction
    $appData->create($document(['tmp/a/two.md' => ['state' => 'requested', 'intent' => 'sideways']]));
    $check('an unknown intent reads as no direction', ReviewStore::intentOf($store->read(), 'tmp/a/two.md') === '');

    echo "17. forgetting an article keeps the site's own record of it\n";
    ['store' => $store, 'appData' => $appData, 'articles' => $articles, 'published' => $published] = $build();
    $articles->rows['/tmp/a/one.md'] = new Article('/tmp/a/one.md', Article::STATE_EXTERNAL);
    $store->setState(['/tmp/a/one.md'], Article::STATE_EXTERNAL, ['owner' => 'a']);
    $store->record(['tmp/a/one.md' => 'a'], 'rev1');
    $removed = $store->forgetArticle(['/tmp/a/one.md', 'tmp/a/never.md']);
    $check('forgetArticle answers only the paths that had an entry', $removed === ['tmp/a/one.md']);
    $check('the entry is gone from the manifest', !isset($store->read()['entries']['tmp/a/one.md']));
    $check('the cache row went with it (nothing would repair it later)', !isset($articles->rows['/tmp/a/one.md']));
    $check('the published entry stays, so the next publish carries the removal', ReviewStore::isPublished($store->read(), 'tmp/a/one.md'));
    $check('and the published cache still has it', isset($published->rows['tmp/a/one.md']));

    echo "17b. the one-shot state rewrite (the visibility migration's own step)\n";
    ['store' => $store, 'appData' => $appData, 'articles' => $articles] = $build();
    $appData->create((string)json_encode([
        'version' => 1,
        'updatedAt' => 7,
        'entries' => [
            'tmp/a/draft.md' => ['state' => 'draft', 'owner' => 'a', 'submittedAt' => 0, 'reviewedBy' => '', 'note' => ''],
            'tmp/a/submitted.md' => ['state' => 'submitted', 'owner' => 'a', 'submittedAt' => 11, 'reviewedBy' => '', 'note' => ''],
            'tmp/a/approved.md' => ['state' => 'approved', 'owner' => 'b', 'submittedAt' => 12, 'reviewedBy' => 'carol', 'note' => ''],
            'tmp/a/published.md' => ['state' => 'published', 'owner' => 'a', 'submittedAt' => 13, 'reviewedBy' => 'carol', 'note' => '',
                'offline' => ['at' => 14, 'by' => 'bob']],
            'tmp/a/rejected.md' => ['state' => 'rejected', 'owner' => 'a', 'submittedAt' => 15, 'reviewedBy' => 'carol', 'note' => 'no'],
            'tmp/a/offline.md' => ['state' => 'offline', 'owner' => 'a', 'submittedAt' => 16, 'reviewedBy' => 'carol', 'note' => ''],
        ],
        'published' => ['tmp/a/published.md' => ['owner' => 'a', 'revision' => 'r1', 'publishedAt' => 13]],
    ]));
    $outcome = $store->migrateStates(
        ['draft' => 'internal', 'submitted' => 'requested', 'approved' => 'requested', 'published' => 'external', 'rejected' => 'internal', 'offline' => 'internal'],
        ['submitted' => 'online', 'approved' => 'online'],
    );
    $migrated = $store->read();
    $check('every entry was rewritten and the document was written', $outcome['entries'] === 6 && $outcome['written'] === true && $outcome['changed'] === 6);
    $check('the outcome counts the mapping per old state', ($outcome['states']['submitted']['requested'] ?? 0) === 1
        && ($outcome['states']['published']['external'] ?? 0) === 1);
    $check('draft becomes internal', $migrated['entries']['tmp/a/draft.md']['state'] === 'internal');
    $check('submitted becomes requested with intent online', $migrated['entries']['tmp/a/submitted.md']['state'] === 'requested'
        && $migrated['entries']['tmp/a/submitted.md']['intent'] === 'online');
    $check('approved becomes requested with intent online, and reviewedBy is decidedBy', $migrated['entries']['tmp/a/approved.md']['state'] === 'requested'
        && $migrated['entries']['tmp/a/approved.md']['decidedBy'] === 'carol');
    $check('published becomes external', $migrated['entries']['tmp/a/published.md']['state'] === 'external');
    $check('the retired request marks are dropped', !isset($migrated['entries']['tmp/a/published.md']['offline'])
        && !isset($migrated['entries']['tmp/a/published.md']['deletion']));
    $check('the old reviewedBy key is gone', !isset($migrated['entries']['tmp/a/published.md']['reviewedBy']));
    $check('rejected becomes internal and keeps its note', $migrated['entries']['tmp/a/rejected.md']['state'] === 'internal'
        && $migrated['entries']['tmp/a/rejected.md']['note'] === 'no');
    $check('offline becomes internal', $migrated['entries']['tmp/a/offline.md']['state'] === 'internal');
    $check('the document says version 2', $migrated['version'] === 2);
    $check('the published list is untouched', ReviewStore::isPublished($migrated, 'tmp/a/published.md'));
    // a second run has nothing to map: the document is already in the current shape and is not rewritten
    $writes = count($appData->writes);
    $second = $store->migrateStates(
        ['draft' => 'internal', 'submitted' => 'requested', 'approved' => 'requested', 'published' => 'external', 'rejected' => 'internal', 'offline' => 'internal'],
        ['submitted' => 'online', 'approved' => 'online'],
    );
    $check('a second run changes nothing and writes nothing', $second['changed'] === 0 && $second['written'] === false && count($appData->writes) === $writes);
    $check('and the document is byte for byte the one the first run wrote', $store->json() === $appData->content);
    ['store' => $store, 'appData' => $appData] = $build();
    $empty = $store->migrateStates(['draft' => 'internal'], []);
    $check('a missing manifest is not created by the migration', $empty['written'] === false && $appData->content === null);

    echo "18. the reset's own rule: which manifest keys are author content\n";
    /*
     * AuthorContentReset drops the author-area keys of both maps. Its filter is a pure function, so it
     * is asserted here rather than through a live reset: the author root, the retired `_posts/<uid>/…`
     * namespace, and never a post of the site's own `_posts/<file>.md`.
     */
    require_once __DIR__ . '/../lib/Service/AuthorAreaService.php';
    require_once __DIR__ . '/../lib/Service/AuthorContentReset.php';
    $uids = ['alice', 'bob'];
    $check('a key under the author root is author content', AuthorContentReset::isAuthorKey('tmp/alice/a.md', 'tmp', $uids));
    $check('at any depth', AuthorContentReset::isAuthorKey('/tmp/alice/notes/deep/a.md', 'tmp', $uids));
    $check('a legacy _posts/<uid>/ key of a known author is author content', AuthorContentReset::isAuthorKey('_posts/alice/a.md', 'tmp', $uids));
    $check('a post of the site itself is not (_posts/<file>.md)', !AuthorContentReset::isAuthorKey('_posts/2026-01-01-hello.md', 'tmp', $uids));
    $check('a legacy key of somebody who is not being reset is not', !AuthorContentReset::isAuthorKey('_posts/carol/a.md', 'tmp', $uids));
    $check('the site skeleton is never author content', !AuthorContentReset::isAuthorKey('index.md', 'tmp', $uids)
        && !AuthorContentReset::isAuthorKey('assets/site.css', 'tmp', $uids));
    $check('an empty key is not', !AuthorContentReset::isAuthorKey('/', 'tmp', $uids));
    $check('the rule follows the configured root', AuthorContentReset::isAuthorKey('people/alice/a.md', 'people', $uids)
        && !AuthorContentReset::isAuthorKey('people/alice/a.md', 'tmp', $uids));

    echo "19. the reset's backup is a tar any reader can open\n";
    /*
     * The snapshot of an author's files is written by hand (PharData is refused where phar.readonly is
     * on), so its bytes are asserted here: a 512-byte ustar header with the name, the size in octal and
     * a valid checksum, the data padded to a block, and the two empty blocks that close an archive.
     */
    $tar = AuthorContentReset::tar(['notes/a.md' => "one\n", 'b.md' => str_repeat('x', 600)]);
    $header = substr($tar, 0, 512);
    $size = (int)octdec(trim(substr($header, 124, 12), "\0 "));
    $check('the header carries the member name', rtrim(substr($header, 0, 100), "\0") === 'notes/a.md');
    $check('the size is octal and right', $size === 4);
    $check('the header is ustar', substr($header, 257, 6) === "ustar\0");
    $stored = (int)octdec(trim(substr($header, 148, 8), "\0 "));
    $sum = 0;
    $blank = substr_replace($header, str_repeat(' ', 8), 148, 8);
    for ($i = 0; $i < 512; $i++) {
        $sum += ord($blank[$i]);
    }
    $check('the checksum matches the header', $stored === $sum);
    $check('the first member is followed by its padding', substr($tar, 512, 4) === "one\n"
        && substr($tar, 516, 8) === str_repeat("\0", 8));
    $second = substr($tar, 1024, 512);
    $check('the second member starts on a block boundary', rtrim(substr($second, 0, 100), "\0") === 'b.md'
        && (int)octdec(trim(substr($second, 124, 12), "\0 ")) === 600);
    // 1024 (two headers) + 512 (first data) + 1024 (second data, padded) + 1024 (end)
    $check('the archive ends with two empty blocks', substr($tar, -1024) === str_repeat("\0", 1024));
    $check('the length is a whole number of blocks', strlen($tar) % 512 === 0);
    $check('an empty snapshot is still a valid, empty archive', AuthorContentReset::tar([]) === str_repeat("\0", 1024));

    echo "\n$pass passed, $fail failed\n";
    exit($fail > 0 ? 1 : 0);
}
