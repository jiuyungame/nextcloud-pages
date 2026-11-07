<?php

/**
 * The review manifest's own logic (OCA\Pages\Service\ReviewStore), checked against in-memory fakes:
 * the appdata storage, the lock around a write cycle, ETag conflicts and retries, encode/decode round
 * trips, the exact JSON shape, corrupt or unreadable files, the cache mirroring, and the partial success
 * of a write whose cache sync fails.
 *
 * The manifest no longer records what the site has or what waits: `online/` and `pending/` do, read
 * through the **real** {@see SiteTrees}, whose two maps come from the bridge (`GET /site/paths`) and
 * only fall back to the Files API when the bridge cannot answer - the fakes below provide that
 * bridge. The probe therefore asserts the **derivation** - a take-down request or the served version
 * deciding the state - as well as the workflows that must leave the manifest alone (a request, an
 * approval) and the ones that must write it (an offline request, a refusal, a take-down), through
 * {@see ArticleReviewService}.
 *
 * The front-matter writer (OCA\Pages\Service\FrontMatter) is probed here too, because its one hard
 * guarantee - a value reads back as it was written - is a pure function of the two classes and needs
 * no instance to check. The registry's sticky `repo_initialised` flag (the fail-closed half of the
 * remote/branch lock) is asserted at the bottom: it is a pure appconfig read/write. Two sections
 * cover the durable author-delta queue (dedupe, cap, bounded replay, the send path) and the Apply
 * source `force` flag the controller hands to the bridge. The last section covers the recoverable
 * edges of the state machine: a candidate whose entry (or article row) was never written, a deleted
 * source's bookkeeping, a source that came back, and the old address a rename leaves behind. Nothing
 * here touches a live instance, so it runs wherever the app's PHP is available.
 *
 * Run from the app root:  snap run --shell nextcloud.occ -c "php tests/code/store-probe.php"
 *
 * The fakes below deliberately shadow the real collaborators (same FQCNs), which is why this file must
 * never be loaded inside the app: it exists to test the store's own logic, not Nextcloud's storage.
 */

declare(strict_types=1);

/*
 * The real registry is loaded before the probe's own stand-in is declared: the stand-in has to extend
 * it (ReviewStore type-hints the real class), and the flag the real class keeps is what section 23
 * asserts. Only the class is needed - its constructor types are resolved when it is instantiated.
 */
namespace {
    require_once __DIR__ . '/../../lib/Service/SiteRegistry.php';

    /*
     * The probe may run where Nextcloud's extensions are not installed. The article index builds a
     * teaser through FrontMatter, which uses the mbstring functions; the byte-based fallback is
     * equivalent for the ASCII fixtures this probe uses and keeps section 36 from depending on the
     * extension. A host that has mbstring keeps using it.
     */
    if (!function_exists('mb_strlen')) {
        function mb_strlen(string $text): int
        {
            return strlen($text);
        }
    }
    if (!function_exists('mb_substr')) {
        function mb_substr(string $text, int $start, ?int $length = null): string
        {
            return $length === null ? substr($text, $start) : substr($text, $start, $length);
        }
    }
}

namespace OCP\Files {
    class NotFoundException extends \Exception
    {
    }

    class NotPermittedException extends \Exception
    {
    }

    /**
     * The one folder surface SiteTrees' Files API fallback uses. The real interface extends Node and
     * carries many more methods; walking a site tree needs the listing and, per entry, the name and
     * the mtime, so that is what this stand-in declares.
     */
    interface Folder
    {
        /** @return list<object> */
        public function getDirectoryListing(): array;

        public function getName(): string;

        public function getMTime(): int;

        public function getPath(): string;
    }

    /** A file in such a folder: the fallback reads its name and its moment, nothing else. */
    class File
    {
        public function __construct(private string $name, private int $mtime = 0)
        {
        }

        public function getName(): string
        {
            return $this->name;
        }

        public function getMTime(): int
        {
            return $this->mtime;
        }
    }

    /** The app's own storage under appdata, as the store uses it. */
    interface IAppData
    {
        public function getFolder(string $name): \OCP\Files\SimpleFS\ISimpleFolder;

        public function newFolder(string $name): \OCP\Files\SimpleFS\ISimpleFolder;
    }

    /**
     * The node surface {@see \OCA\Pages\Service\AuthorDeltaService} reads in a file event: the
     * absolute path, which the service turns into `[uid, site-relative target]`. The real class also
     * carries the folder check, so the `Folder` interface above stays the one a folder event matches.
     */
    class Node
    {
        public function __construct(private string $path)
        {
        }

        public function getPath(): string
        {
            return $this->path;
        }
    }

    /**
     * A folder node: a rename of a folder reaches the listener as the `Node` half as well (the old
     * path is already gone), so the folder branch answers from `$source instanceof Folder` - which is
     * what this class provides, together with the path the target is built from.
     */
    class FakeFolderNode extends Node implements Folder
    {
        /** @return list<object> */
        public function getDirectoryListing(): array
        {
            return [];
        }

        public function getName(): string
        {
            return basename($this->getPath());
        }

        public function getMTime(): int
        {
            return 0;
        }
    }
}

namespace OCP\Files\SimpleFS {
    interface ISimpleFolder
    {
        public function getName(): string;

        public function fileExists(string $name): bool;

        public function getFile(string $name): ISimpleFile;

        /** @return list<ISimpleFile> */
        public function getDirectoryListing(): array;

        /** @param string|resource|null $content */
        public function newFile(string $name, $content = null): ISimpleFile;
    }

    interface ISimpleFile
    {
        public function getName(): string;

        public function getContent(): string;

        /** @param string|resource $data */
        public function putContent($data): void;

        public function getETag(): string;
    }
}

namespace OCP\Lock {
    class LockedException extends \Exception
    {
        public function __construct(private string $path = '')
        {
            parent::__construct($path);
        }

        public function getPath(): string
        {
            return $this->path;
        }
    }

    interface ILockingProvider
    {
        public const LOCK_SHARED = 1;
        public const LOCK_EXCLUSIVE = 2;

        public function acquireLock(string $path, int $type, ?string $readablePath = null): void;

        public function releaseLock(string $path, int $type): void;
    }
}

/*
 * The thin AppFramework surface {@see \OCA\Pages\Controller\BuildController} needs, so section 31
 * can instantiate the real controller and assert the `force` it hands to the bridge. The controller
 * itself is the thing under test; only the framework's request/response wrappers are stand-ins.
 */
namespace OCP\AppFramework {
    class Http
    {
        public const STATUS_BAD_REQUEST = 400;
        public const STATUS_UNAUTHORIZED = 401;
        public const STATUS_FORBIDDEN = 403;
        public const STATUS_NOT_FOUND = 404;
        public const STATUS_CONFLICT = 409;
        public const STATUS_PRECONDITION_FAILED = 412;
        public const STATUS_TOO_MANY_REQUESTS = 429;
        public const STATUS_BAD_GATEWAY = 502;
        public const STATUS_SERVICE_UNAVAILABLE = 503;
    }

    class Controller
    {
        public function __construct(protected string $appName, protected \OCP\IRequest $request)
        {
        }
    }
}

namespace OCP\AppFramework\Http {
    class DataResponse
    {
        /** @param array<string,mixed> $headers */
        public function __construct(
            private mixed $data = [],
            private int $status = 200,
            private array $headers = [],
        ) {
        }

        public function getData(): mixed
        {
            return $this->data;
        }

        public function getStatus(): int
        {
            return $this->status;
        }

        public function addHeader(string $name, string $value): void
        {
            $this->headers[$name] = $value;
        }
    }
}

namespace OCP {
    interface IRequest
    {
        public function getParam(string $key, mixed $default = null): mixed;
    }

    interface IUser
    {
        public function getUID(): string;
    }

    interface IUserSession
    {
        public function getUser(): ?IUser;
    }

    interface IGroupManager
    {
        public function isAdmin(string $userId): bool;

        /** @return list<string> */
        public function getUserGroupIds(IUser $user): array;
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

        public function getAppValue(string $appId, string $key, string $default = ''): string;

        public function setAppValue(string $appId, string $key, string $value): void;

        public function deleteAppValue(string $appId, string $key): void;
    }

    /** The registry only type-hints it: no user lookup runs on the paths this probe asserts. */
    interface IUserManager
    {
    }

    /** The review service's rejection link: the route name is all the probe needs. */
    interface IURLGenerator
    {
        /** @param array<string,mixed> $params */
        public function linkToRouteAbsolute(string $route, array $params = []): string;
    }

    /**
     * The container access the review service falls back to for its logger. Only reached when a
     * notification cannot be sent; the fake keeps that path from fataling.
     */
    class Server
    {
        public static ?\Psr\Log\LoggerInterface $logger = null;

        public static function get(string $class): mixed
        {
            return self::$logger;
        }
    }
}

namespace OCP\Notification {
    /** The one notification object the review service builds, with the fluent setters it chains. */
    class Notification
    {
        /** @var array<string,mixed> */
        public array $data = [];

        public function setApp(string $app): self
        {
            $this->data['app'] = $app;

            return $this;
        }

        public function setUser(string $user): self
        {
            $this->data['user'] = $user;

            return $this;
        }

        public function setDateTime(\DateTime $dateTime): self
        {
            $this->data['at'] = $dateTime->getTimestamp();

            return $this;
        }

        public function setObject(string $type, string $id): self
        {
            $this->data['object'] = [$type, $id];

            return $this;
        }

        /** @param array<string,mixed> $parameters */
        public function setSubject(string $subject, array $parameters = []): self
        {
            $this->data['subject'] = $subject;
            $this->data['parameters'] = $parameters;

            return $this;
        }

        public function setLink(string $link): self
        {
            $this->data['link'] = $link;

            return $this;
        }
    }

    interface IManager
    {
        public function createNotification(): Notification;

        public function notify(Notification $notification): void;
    }
}

namespace OCA\Pages\AppInfo {
    /** The app id the notification carries; the real class boots Nextcloud, which this probe never does. */
    class Application
    {
        public const APP_ID = 'pages';
    }
}

namespace OCA\Pages\Notification {
    /** The constants the review service names; the real notifier renders them. */
    class Notifier
    {
        public const SUBJECT_REJECTED = 'publication_rejected';
        public const DIRECTION_ONLINE = 'online';
        public const DIRECTION_OFFLINE = 'offline';
        public const DIRECTION_TAKE_DOWN = 'take-down';
    }
}

namespace OCP\Security {
    /** The two calls the registry makes on it (the secrets); a reversible marker is enough. */
    interface ICrypto
    {
        public function encrypt(string $plaintext, string $password = ''): string;

        public function decrypt(string $ciphertext, string $password = ''): string;
    }
}

namespace Psr\Log {
    interface LoggerInterface
    {
        public function debug(string|\Stringable $message, array $context = []): void;

        public function info(string|\Stringable $message, array $context = []): void;

        public function warning(string|\Stringable $message, array $context = []): void;

        public function error(string|\Stringable $message, array $context = []): void;
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

        /** The two columns the real index reads back from a row it did not just write. */
        public string $title = '';
        public string $tags = '';

        public function __construct(
            public string $path = '',
            public string $state = self::STATE_INTERNAL,
            public ?string $note = null,
            public int $submittedAt = 0,
            public int $publishedAt = 0,
            public string $revision = '',
            public string $ownerUid = '',
        ) {
        }

        public function getTitle(): string
        {
            return $this->title;
        }

        public function getTags(): string
        {
            return $this->tags;
        }

        public function getId(): int
        {
            return crc32($this->path);
        }

        public function getPath(): string
        {
            return $this->path;
        }

        public function getOwnerUid(): string
        {
            return $this->ownerUid;
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

    /** In-memory stand-in for the article cache. */
    class ArticleMapper
    {
        /** @var array<string,Article> */
        public array $rows = [];
        /** @var list<array<string,mixed>> */
        public array $calls = [];
        /**
         * Armed by a test: reading the table fails, which is what a cache sync that cannot follow the
         * manifest looks like from the store's side (`syncArticles()` is the first thing it does).
         */
        public bool $failReads = false;

        public function findAll(): array
        {
            if ($this->failReads) {
                throw new \RuntimeException('the article cache cannot be read');
            }

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

        /** The real index's insert-or-refresh path; only the probe's section 36 reaches it. */
        public function upsertByPath(string $path, string $ownerUid, string $title, string $tags): Article
        {
            $row = $this->rows[$path] ?? new Article($path, Article::STATE_INTERNAL, null, 0, 0, '', $ownerUid);
            $row->ownerUid = $ownerUid;
            $row->title = $title;
            $row->tags = $tags;
            $this->rows[$path] = $row;

            return $row;
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

        /** The full reset's own clearing: every row, gone (the store calls this from clear()). */
        public function deleteAll(): int
        {
            $count = count($this->rows);
            $this->rows = [];
            $this->calls[] = ['op' => 'deleteAll'];

            return $count;
        }
    }

    /** The community queries the real index adds to every row; section 36 has no votes or comments. */
    class VoteMapper
    {
        /** @return array<int,array{up:int,down:int}> */
        public function aggregateForArticles(array $ids): array
        {
            return [];
        }

        /** @return array<int,int> */
        public function findForArticlesAndVoter(array $ids, string $voterUid): array
        {
            return [];
        }
    }

    class CommentMapper
    {
        /** @return array<int,int> */
        public function countForArticles(array $ids): array
        {
            return [];
        }
    }
}

namespace OCA\Pages\Service {
    /**
     * In-memory stand-in for the Files API: the author files the review service checks before an
     * approval, and the folder fallback {@see SiteTrees} reads when the bridge cannot answer.
     */
    class FileService
    {
        /** @var array<string,string> `uid:Pages/rel` => content, the author files a review may check */
        public array $authorFiles = [];
        /** @var array<string,\OCP\Files\Folder> `uid:path` => folder, the Files API fallback's input */
        public array $folders = [];
        /** @var list<string> every `existingFolder()` call, as `uid:path` */
        public array $folderRequests = [];

        /** @var array<string,list<array{type:string,path:string}>> `uid:path` => the nodes a scan walks */
        public array $trees = [];

        public function fileExists(string $userId, string $ncPath, string $relPath): bool
        {
            return array_key_exists($userId . ':' . $ncPath . '/' . $relPath, $this->authorFiles);
        }

        /** @return array{content:string} */
        public function read(string $userId, string $ncPath, string $relPath): array
        {
            $key = $userId . ':' . $ncPath . '/' . $relPath;
            if (array_key_exists($key, $this->authorFiles)) {
                return ['content' => $this->authorFiles[$key]];
            }
            throw new \OCP\Files\NotFoundException('no file ' . $key);
        }

        public function siteExists(string $userId, string $ncPath): bool
        {
            return array_key_exists($userId . ':' . $ncPath, $this->trees)
                || array_key_exists($userId . ':' . $ncPath, $this->folders);
        }

        /** @return array{nodes:list<array{type:string,path:string}>} */
        public function tree(string $userId, string $ncPath): array
        {
            return ['nodes' => $this->trees[$userId . ':' . $ncPath] ?? []];
        }

        /**
         * The Files API fallback of {@see SiteTrees}: a directory is there only when a section handed
         * one over - what a site whose bridge is down and whose folder holds nothing looks like.
         */
        public function existingFolder(string $userId, string $ncPath): ?\OCP\Files\Folder
        {
            $key = $userId . ':' . $ncPath;
            $this->folderRequests[] = $key;

            return $this->folders[$key] ?? null;
        }
    }

    /**
     * In-memory stand-in for the author space: a target is `<author_root>/<uid>/<rel>`, and the file is
     * `Pages/<rel>` in that user's own files. Enough for the one caller the probe has: mapping targets
     * to author-area files.
     */
    class AuthorAreaService
    {
        public function __construct(public string $root = 'tmp')
        {
        }

        public function area(): string
        {
            return 'Pages';
        }

        public function authorRoot(): string
        {
            return $this->root;
        }

        public function targetFor(string $uid, string $rel): string
        {
            $rel = ltrim(trim(str_replace('\\', '/', $rel)), '/');

            return $rel === '' ? '' : $this->root . '/' . $uid . '/' . $rel;
        }

        public function isMirrored(string $rel): bool
        {
            return trim($rel) !== '';
        }

        public function isRenderable(string $rel): bool
        {
            $extension = strtolower(pathinfo($rel, PATHINFO_EXTENSION));

            return in_array($extension, ['md', 'markdown', 'html'], true);
        }

        public function displayName(string $uid): string
        {
            return $uid;
        }

        public function uidOfTarget(string $target): ?string
        {
            $clean = ltrim(trim(str_replace('\\', '/', $target)), '/');
            $prefix = $this->root . '/';
            if (!str_starts_with($clean, $prefix)) {
                return null;
            }
            $rest = substr($clean, strlen($prefix));
            $slash = strpos($rest, '/');
            if ($slash === false || $slash === 0) {
                return null;
            }

            return substr($rest, 0, $slash);
        }

        /** @return array{ok:bool,code:string,uid:string,file:string,target:string} */
        public function resolve(string $uid, string $path): array
        {
            $clean = ltrim(trim(str_replace('\\', '/', $path)), '/');
            $prefix = $this->root . '/' . $uid . '/';
            if (!str_starts_with($clean, $prefix)) {
                return ['ok' => false, 'code' => 'foreign', 'uid' => $uid, 'file' => '', 'target' => $clean];
            }

            return ['ok' => true, 'code' => 'ok', 'uid' => $uid, 'file' => substr($clean, strlen($prefix)), 'target' => $clean];
        }
    }

    /** The address builder the real index asks for an author's page; section 36 only needs a value. */
    class PageUrlService
    {
        /** @param array<string,mixed> $data */
        public function forAuthorPage(string $siteUser, string $ncPath, string $authorUid, string $rel, array $data): string
        {
            return '/' . $authorUid . '/' . basename($rel) . '.html';
        }
    }

    /**
     * The registry the review service asks for the site, with none of the real class's collaborators:
     * the real one (the one that keeps `repo_initialised`) is loaded at the top of this file and
     * exercised in section 23, so this stand-in only has to answer getSite().
     */
    class FakeSiteRegistry extends SiteRegistry
    {
        /**
         * @param list<string> $authorGroups the authoring grant; `[]` means every signed-in user
         * @param list<string> $reviewGroups the review-and-publish grant; `[]` means administrators only
         */
        public function __construct(
            public ?array $site = ['nc_user' => 'siteuser', 'nc_path' => '/Sites/site'],
            public array $authorGroups = [],
            public array $reviewGroups = [],
        ) {
        }

        public function getSite(): ?array
        {
            return $this->site;
        }

        public function getAuthorGroups(): array
        {
            return $this->authorGroups;
        }

        public function getReviewGroups(): array
        {
            return $this->reviewGroups;
        }

        public function getAuthorRoot(): string
        {
            return 'tmp';
        }

        public function getPublishPushFailure(): ?array
        {
            return null;
        }
    }

    /**
     * The author-space scan {@see \OCA\Pages\Service\AuthorDeltaService} asks for only in the
     * one-shot overwrite; the file-event paths this probe drives never reach it.
     */
    class AuthorSourcesService
    {
        /** @var list<string> the uids whose author areas the real index scans (section 36) */
        public array $authors = [];

        /** @return list<string> */
        public function authors(): array
        {
            return $this->authors;
        }

        /** @return array<string,mixed> */
        public function overwritePayload(): array
        {
            return ['authors' => [], 'purge' => [], 'online' => []];
        }

        /** @return list<array{uid:string,area:string}> */
        public function authorAreas(): array
        {
            return [['uid' => 'alice', 'area' => 'Pages']];
        }
    }

    /*
     * There is no stand-in for SiteTrees: the **real** class is loaded below (lib/Service/SiteTrees.php)
     * and driven by the fake BridgeClient, because that is the change this probe has to prove - the
     * two content directories are read from the bridge, not through the Nextcloud filecache. The
     * probe's own way of moving bytes (publish/freeze/approve/takeDown) lives on {@see \Probe\Trees}.
     */

    /**
     * The bridge, recording the ops the review workflow sends. `$onOp` lets a test apply the op's
     * effect to the fake trees, which is what the real bridge does to the two folders.
     *
     * `$paths` is what the bridge's `GET /site/paths` answers: the two directories it wrote, as
     * `target => mtime`. The review ops below fail with `$fail`; the paths read fails with
     * `$pathsFail`, so a section can model "the bridge refuses this op" and "the bridge is down"
     * separately - the app treats the second one by falling back to the Files API.
     */
    class BridgeClient
    {
        /** @var list<array{op:string,uid:string,target:string,digest?:string}> */
        public array $ops = [];
        /** @var list<array<string,mixed>> */
        public array $mirrors = [];
        public int $notifies = 0;
        public ?\Throwable $fail = null;
        /** @var callable(string,string):void|null */
        public $onOp = null;
        /** The digest a `candidate` answers: the sha256 of the frozen bytes, in the probe's own words. */
        public string $candidateDigest = '9f2c47d0c1e3a8b4d5f60718293a4b5c6d7e8f901234567890abcdef12345678';
        /** @var array{online:array<string,int>,pending:array<string,int>} `GET /site/paths` */
        public array $paths = ['online' => [], 'pending' => []];
        /** How many times SiteTrees asked for the snapshot (one per read, not one per question). */
        public int $snapshots = 0;
        public ?\Throwable $pathsFail = null;
        /** @var list<array<string,mixed>> every author delta the service sent */
        public array $authorDeltas = [];
        /** @var list<int|null> the per-call timeout of each author delta (null = the lock-bound default) */
        public array $authorDeltaTimeouts = [];
        /** Armed by a test: the next author delta fails (the real one answers `ok: true`). */
        public ?\Throwable $deltaFail = null;

        /** @return array<string,mixed> */
        public function authorDelta(array $body, ?int $timeout = null): array
        {
            if ($this->deltaFail !== null) {
                throw $this->deltaFail;
            }
            $this->authorDeltas[] = $body;
            $this->authorDeltaTimeouts[] = $timeout;

            return ['ok' => true];
        }

        /** @return array{online:array<string,int>,pending:array<string,int>} */
        public function pathsSnapshot(): array
        {
            $this->snapshots++;
            if ($this->pathsFail !== null) {
                throw $this->pathsFail;
            }

            return $this->paths;
        }

        /** @return array<string,mixed> */
        public function reviewOp(string $op, string $uid, string $target, ?string $digest = null): array
        {
            if ($this->fail !== null) {
                throw $this->fail;
            }
            $clean = ltrim($target, '/');
            $recorded = ['op' => $op, 'uid' => $uid, 'target' => $clean];
            if ($digest !== null) {
                $recorded['digest'] = $digest;
            }
            $this->ops[] = $recorded;
            if ($this->onOp !== null) {
                ($this->onOp)($op, $clean);
            }

            $answer = ['ok' => true, 'op' => $op];
            if ($op === 'candidate') {
                // the bridge answers the identity of the bytes it froze; the app records it
                $answer['digest'] = $this->candidateDigest;
            }

            return $answer;
        }

        /** @var array<string,mixed>|null a configured answer (the refused-round shape) */
        public ?array $mirrorAnswer = null;

        /**
         * @param list<array{uid:string,area:string}> $authors
         * @return array<string,mixed>
         */
        public function mirror(array $authors = [], bool $build = true, bool $buildOnlinepreview = false, bool $dryRun = false, bool $force = false): array
        {
            if ($this->fail !== null) {
                throw $this->fail;
            }
            $this->mirrors[] = compact('authors', 'build', 'buildOnlinepreview', 'dryRun', 'force');
            if ($this->mirrorAnswer !== null) {
                return $this->mirrorAnswer;
            }

            return [
                'ok' => true,
                'op' => 'mirror',
                'rebuilt' => true,
                'files' => ['work' => 1, 'preview' => 1, 'onlinepreview' => 1],
                'build' => ['ok' => true],
            ];
        }

        /** @return array<string,mixed> */
        public function notify(): array
        {
            $this->notifies++;

            return ['ok' => true];
        }
    }

    /** The reverse index: the review service registers a missing row through it. */
    class ArticleIndexService
    {
        /** @var list<string> */
        public array $registered = [];

        public function register(string $uid, string $area, string $rel, string $target): void
        {
            $this->registered[] = $target;
        }
    }

    /** The orphan handover, recorded rather than performed. */
    class OrphanTransfer
    {
        /** @var list<string> */
        public array $transfers = [];

        /** @return array<string,mixed> */
        public function transfer(string $target): array
        {
            $this->transfers[] = $target;

            return [
                'orphan' => 'Admin',
                'target' => 'tmp/Admin/one.md',
                'site_path' => '/Sites/site/source/tmp/Admin/one.md',
                'bytes' => 1,
                'created' => true,
                'notified' => true,
                'rebuilt' => true,
                'message' => 'moved',
            ];
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
        /** @var array<string,string> the copies the store takes aside (`manifest.corrupt-<ts>.json`) */
        public array $sidelined = [];
        /** Armed by a read while a conflict is pending: the next file open is where the other writer wins. */
        public bool $replaceOnNextOpen = false;
        private int $generation = 0;
        /** Bumped per replacement so the other writer's bytes differ even when the ETag does not. */
        private int $replacements = 0;

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

        /**
         * The document another writer left: what a write has to notice before it overwrites it.
         *
         * The entry is the version-4 shape this store writes. `note` changes on every replacement so
         * the bytes differ even on a storage that keeps the ETag for two writes inside one second -
         * that is the case ReviewStore::write() catches by comparing the content.
         */
        public function replaceByOtherWriter(): void
        {
            $other = json_decode($this->content ?? '{}', true);
            $other['entries']['tmp/other/two.md'] = [
                'owner' => 'other',
                'candidateDigest' => '',
                'downRequest' => null,
                'decidedBy' => '',
                'note' => 'other writer #' . (++$this->replacements),
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

        /** @return list<ISimpleFile> */
        public function getDirectoryListing(): array
        {
            $out = [];
            if ($this->appData->content !== null) {
                $out[] = new FakeFile($this->appData);
            }
            foreach (array_keys($this->appData->sidelined) as $name) {
                $out[] = new FakeSideFile($this->appData, $name);
            }

            return $out;
        }

        public function newFile(string $name, $content = null): ISimpleFile
        {
            if ($name === ReviewStore::FILE) {
                $this->appData->create((string)$content);

                return new FakeFile($this->appData);
            }
            // the one other name the store writes: the copy taken aside when the manifest is corrupt
            if (!str_starts_with($name, ReviewStore::CORRUPT_PREFIX)) {
                throw new NotPermittedException('unexpected file ' . $name);
            }
            $this->appData->sidelined[$name] = (string)$content;

            return new FakeSideFile($this->appData, $name);
        }
    }

    final class FakeFile implements ISimpleFile
    {
        public function __construct(private FakeAppData $appData)
        {
        }

        public function getName(): string
        {
            return ReviewStore::FILE;
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

    /** One of the copies the store takes aside next to the manifest (`manifest.corrupt-<ts>.json`). */
    final class FakeSideFile implements ISimpleFile
    {
        public function __construct(private FakeAppData $appData, private string $name)
        {
        }

        public function getName(): string
        {
            return $this->name;
        }

        public function getContent(): string
        {
            return $this->appData->sidelined[$this->name] ?? '';
        }

        public function putContent($data): void
        {
            $this->appData->sidelined[$this->name] = (string)$data;
        }

        public function getETag(): string
        {
            return 'side-etag';
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
        /** How many of the next `acquireLock()` calls fail as if another writer held the key. */
        public int $failAcquires = 0;

        public function acquireLock(string $path, int $type, ?string $readablePath = null): void
        {
            if ($this->failAcquires > 0) {
                $this->failAcquires--;
                throw new LockedException($path);
            }
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

    class FakeConfig implements \OCP\IConfig
    {
        /** @var array<string,string> appconfig values, keyed `<app>/<key>` */
        public array $appValues = [];
        /** @var list<string> every write this fake saw, as `<app>/<key>`: "written twice" is a bug */
        public array $writes = [];

        public function getSystemValueString(string $key, string $default = ''): string
        {
            return $key === 'instanceid' ? 'probe' : $default;
        }

        public function getAppValue(string $appId, string $key, string $default = ''): string
        {
            return $this->appValues[$appId . '/' . $key] ?? $default;
        }

        public function setAppValue(string $appId, string $key, string $value): void
        {
            $this->appValues[$appId . '/' . $key] = $value;
            $this->writes[] = $appId . '/' . $key;
        }

        public function deleteAppValue(string $appId, string $key): void
        {
            unset($this->appValues[$appId . '/' . $key]);
        }
    }

    /**
     * The appconfig of the delta-queue section, recording whether each queue write happened while
     * the queue's own lock was held. A write outside the lock is exactly the read-modify-write race
     * the queue must not have.
     */
    final class QueueConfig extends FakeConfig
    {
        public ?FakeLocking $locking = null;
        /** @var list<bool> one entry per write of `pages/delta_queue` */
        public array $queueWritesLocked = [];

        public function setAppValue(string $appId, string $key, string $value): void
        {
            if ($appId === 'pages' && $key === \OCA\Pages\Service\DeltaQueue::KEY) {
                $this->queueWritesLocked[] = $this->locking !== null
                    && in_array(\OCA\Pages\Service\DeltaQueue::LOCK_KEY, $this->locking->held(), true);
            }
            parent::setAppValue($appId, $key, $value);
        }
    }

    /** Crypto as the registry uses it: the secrets only. A reversible marker is enough here. */
    final class FakeCrypto implements \OCP\Security\ICrypto
    {
        public function encrypt(string $plaintext, string $password = ''): string
        {
            return $plaintext === '' ? '' : 'enc:' . base64_encode($plaintext);
        }

        public function decrypt(string $ciphertext, string $password = ''): string
        {
            return str_starts_with($ciphertext, 'enc:')
                ? (string)base64_decode(substr($ciphertext, 4), true)
                : $ciphertext;
        }
    }

    /** The registry's fourth collaborator: nothing on the asserted paths calls it. */
    final class FakeUserManager implements \OCP\IUserManager
    {
    }

    /** One signed-in administrator, for the controller's capability guards. */
    final class FakeUser implements \OCP\IUser
    {
        public function __construct(private string $uid = 'admin')
        {
        }

        public function getUID(): string
        {
            return $this->uid;
        }
    }

    /** The request body the controller reads: one parameter map, no framework. */
    final class FakeRequest implements \OCP\IRequest
    {
        /** @param array<string,mixed> $params */
        public function __construct(public array $params = [])
        {
        }

        public function getParam(string $key, mixed $default = null): mixed
        {
            return $this->params[$key] ?? $default;
        }
    }

    final class FakeUserSession implements \OCP\IUserSession
    {
        public function __construct(private ?string $uid = 'admin')
        {
        }

        public function getUser(): ?\OCP\IUser
        {
            return $this->uid === null ? null : new FakeUser($this->uid);
        }
    }

    /**
     * The group manager the capability guards read: an administrator passes without a group, a plain
     * user is decided by the lists the tests configure.
     */
    final class FakeGroupManager implements \OCP\IGroupManager
    {
        /** @param list<string> $groups */
        public function __construct(private bool $admin = true, private array $groups = [])
        {
        }

        public function isAdmin(string $userId): bool
        {
            return $this->admin;
        }

        /** @return list<string> */
        public function getUserGroupIds(\OCP\IUser $user): array
        {
            return $this->groups;
        }
    }

    final class FakeLogger implements \Psr\Log\LoggerInterface
    {
        /** @var list<array{level:string,message:string,context:array<string,mixed>}> */
        public array $lines = [];

        public function debug(string|\Stringable $message, array $context = []): void
        {
            $this->lines[] = ['level' => 'debug', 'message' => (string)$message, 'context' => $context];
        }

        public function warning(string|\Stringable $message, array $context = []): void
        {
            $this->lines[] = ['level' => 'warning', 'message' => (string)$message, 'context' => $context];
        }

        public function info(string|\Stringable $message, array $context = []): void
        {
            $this->lines[] = ['level' => 'info', 'message' => (string)$message, 'context' => $context];
        }

        public function error(string|\Stringable $message, array $context = []): void
        {
            $this->lines[] = ['level' => 'error', 'message' => (string)$message, 'context' => $context];
        }

        /** @return list<string> */
        public function warnings(): array
        {
            return array_column(array_filter($this->lines, static fn (array $line): bool => $line['level'] === 'warning'), 'message');
        }

        /** @return list<string> */
        public function errors(): array
        {
            return array_column(array_filter($this->lines, static fn (array $line): bool => $line['level'] === 'error'), 'message');
        }
    }
    /**
     * The real {@see \OCA\Pages\Service\SiteTrees} plus the probe's way of moving bytes under it.
     *
     * The store is handed the real class - its public methods are what the review workflow calls, and
     * the point of the probe is that they answer from the bridge's snapshot. The helpers below are
     * what a bridge operation leaves behind in that snapshot (`publish`, `candidate`, `discard`,
     * `take-down`), so a section keeps saying `$trees->publish(...)` and the store's next question is
     * answered by the new state. Each helper drops the cached read, exactly as the app does through
     * `ReviewStore::refreshTrees()` after a bridge call: a test that skipped that would be asserting
     * the cache, not the directories.
     */
    final class Trees
    {
        /** How many times a section asked for the cache to be dropped. */
        public int $forgets = 0;

        public function __construct(
            public \OCA\Pages\Service\SiteTrees $trees,
            public \OCA\Pages\Service\BridgeClient $bridge,
        ) {
        }

        /** @return array<string,int> */
        public function online(): array
        {
            return $this->trees->online();
        }

        /** @return array<string,int> */
        public function pending(): array
        {
            return $this->trees->pending();
        }

        public function isOnline(string $target): bool
        {
            return $this->trees->isOnline($target);
        }

        public function isPending(string $target): bool
        {
            return $this->trees->isPending($target);
        }

        public function onlineStamp(string $target): int
        {
            return $this->trees->onlineStamp($target);
        }

        public function pendingStamp(string $target): int
        {
            return $this->trees->pendingStamp($target);
        }

        /** @return list<string> */
        public function onlineTargets(): array
        {
            return $this->trees->onlineTargets();
        }

        /** @return list<string> */
        public function pendingTargets(): array
        {
            return $this->trees->pendingTargets();
        }

        /** @return array{pending:int,online:int} */
        public function counts(): array
        {
            return $this->trees->counts();
        }

        public function forget(): void
        {
            $this->forgets++;
            $this->trees->forget();
        }

        /** What the bridge's `publish` op leaves behind: the candidate becomes the served version. */
        public function publish(string $target, int $at = 100): void
        {
            $this->bridge->paths['online'][self::key($target)] = $at;
            $this->forget();
        }

        /** What the bridge's `candidate` op leaves behind. */
        public function freeze(string $target, int $at = 100): void
        {
            $this->bridge->paths['pending'][self::key($target)] = $at;
            $this->forget();
        }

        /** What `publish` does to a frozen candidate: it becomes `online/`, `pending/` is gone. */
        public function approve(string $target, int $at = 100): void
        {
            $key = self::key($target);
            unset($this->bridge->paths['pending'][$key]);
            $this->bridge->paths['online'][$key] = $at;
            $this->forget();
        }

        /** What `discard` leaves behind: the candidate goes, the served version stays. */
        public function drop(string $target): void
        {
            unset($this->bridge->paths['pending'][self::key($target)]);
            $this->forget();
        }

        /** What `take-down` leaves behind. */
        public function takeDown(string $target): void
        {
            unset($this->bridge->paths['online'][self::key($target)]);
            $this->forget();
        }

        private static function key(string $target): string
        {
            return ltrim(trim(str_replace('\\', '/', $target)), '/');
        }
    }

    /** One directory the Files API fallback walks, in memory: files and subfolders with mtimes. */
    final class FakeSiteFolder implements \OCP\Files\Folder
    {
        /** @param list<object> $children */
        public function __construct(
            private string $name,
            public array $children = [],
            private int $mtime = 0,
        ) {
        }

        /** @return list<object> */
        public function getDirectoryListing(): array
        {
            return $this->children;
        }

        public function getName(): string
        {
            return $this->name;
        }

        public function getMTime(): int
        {
            return $this->mtime;
        }

        public function getPath(): string
        {
            return $this->name;
        }
    }

    /**
     * The site folder tree {@see \OCA\Pages\Service\SourceReplacementService} walks and mutates.
     *
     * The probe's OCP\Files\Folder stand-in only carries what SiteTrees reads; a replacement also needs
     * the mutating half (getParent/delete/newFolder) and a file's content, so those live on this
     * concrete class while the shared interface stays as narrow as the rest of the probe uses.
     */
    final class ReplacementFolder implements \OCP\Files\Folder
    {
        /** @var array<string,ReplacementFolder|ReplacementFile> */
        public array $children = [];
        /** How many of the next delete() calls fail as if another request held the folder lock. */
        public int $deleteLockTimes = 0;
        /** @var list<string> */
        public array $deleteAttempts = [];

        public function __construct(private string $name, public ?ReplacementFolder $parent = null)
        {
        }

        /** @return list<ReplacementFolder|ReplacementFile> */
        public function getDirectoryListing(): array
        {
            return array_values($this->children);
        }

        public function getName(): string
        {
            return $this->name;
        }

        public function getMTime(): int
        {
            return 0;
        }

        public function getPath(): string
        {
            return $this->parent === null ? '' : $this->parent->getPath() . '/' . $this->name;
        }

        public function getParent(): ReplacementFolder
        {
            if ($this->parent === null) {
                throw new \RuntimeException('the root has no parent');
            }

            return $this->parent;
        }

        public function newFolder(string $name): ReplacementFolder
        {
            $folder = new ReplacementFolder($name, $this);
            $this->children[$name] = $folder;

            return $folder;
        }

        public function newFile(string $name, string $content = ''): ReplacementFile
        {
            $file = new ReplacementFile($name, $content, $this);
            $this->children[$name] = $file;

            return $file;
        }

        public function delete(): void
        {
            $this->deleteAttempts[] = $this->getPath();
            if ($this->deleteLockTimes > 0) {
                $this->deleteLockTimes--;
                throw new \OCP\Lock\LockedException($this->getPath());
            }
            if ($this->parent === null) {
                throw new \RuntimeException('the root cannot be deleted');
            }
            unset($this->parent->children[$this->name]);
        }

        public function file(string $rel): ?ReplacementFile
        {
            $folder = $this;
            $segments = $this->segments($rel);
            foreach ($segments as $index => $segment) {
                $child = $folder->children[$segment] ?? null;
                if ($child === null) {
                    return null;
                }
                if ($index === count($segments) - 1) {
                    return $child instanceof ReplacementFile ? $child : null;
                }
                if (!$child instanceof ReplacementFolder) {
                    return null;
                }
                $folder = $child;
            }

            return null;
        }

        /** @return list<string> */
        private function segments(string $path): array
        {
            return array_values(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== ''));
        }
    }

    /** One file in that tree: its bytes are what backup() copies and restore() puts back. */
    final class ReplacementFile
    {
        /** How many of the next delete() calls fail as if the file itself were locked. */
        public int $deleteLockTimes = 0;

        public function __construct(
            private string $name,
            public string $content,
            private ?ReplacementFolder $parent = null,
        ) {
        }

        public function getName(): string
        {
            return $this->name;
        }

        public function getContent(): string
        {
            return $this->content;
        }

        public function delete(): void
        {
            if ($this->deleteLockTimes > 0) {
                $this->deleteLockTimes--;
                throw new \OCP\Lock\LockedException($this->name);
            }
            if ($this->parent !== null) {
                unset($this->parent->children[$this->name]);
            }
        }
    }

    /**
     * The probe's FileService stand-in plus the three calls a replacement makes: existingFolder() is
     * inherited, ensureSiteFolder() and write() are added here. `writeError` arms one failing write -
     * the non-lock path - without introducing a retry knob in the service under test.
     */
    final class ReplacementFiles extends \OCA\Pages\Service\FileService
    {
        public ReplacementFolder $root;
        public int $ensureCalls = 0;
        /** @var list<array{rel:string,content:string}> */
        public array $writes = [];
        public ?\Throwable $writeError = null;

        public function __construct()
        {
            $this->root = new ReplacementFolder('');
        }

        public function existingFolder(string $userId, string $ncPath): ?\OCP\Files\Folder
        {
            return $this->folderAt($ncPath);
        }

        public function ensureSiteFolder(string $userId, string $ncPath): \OCP\Files\Folder
        {
            $this->ensureCalls++;

            return $this->folderAtOrCreate($ncPath);
        }

        public function write(
            string $userId,
            string $ncPath,
            string $relPath,
            string $content,
            ?string $baseEtag = null,
            bool $create = false,
        ): array {
            if ($this->writeError !== null) {
                $error = $this->writeError;
                $this->writeError = null;
                throw $error;
            }
            $parent = dirname($relPath);
            $folder = $this->folderAtOrCreate($ncPath . ($parent !== '.' && $parent !== '' ? '/' . $parent : ''));
            $node = $folder->children[basename($relPath)] ?? null;
            if ($node instanceof ReplacementFile) {
                $node->content = $content;
            } else {
                $folder->newFile(basename($relPath), $content);
            }
            $this->writes[] = ['rel' => $relPath, 'content' => $content];

            return ['path' => $relPath, 'etag' => 'probe-etag', 'size' => strlen($content), 'mtime' => 0, 'created' => true];
        }

        public function folderAt(string $path): ?ReplacementFolder
        {
            $folder = $this->root;
            foreach ($this->segments($path) as $segment) {
                $child = $folder->children[$segment] ?? null;
                if (!$child instanceof ReplacementFolder) {
                    return null;
                }
                $folder = $child;
            }

            return $folder;
        }

        public function folderAtOrCreate(string $path): ReplacementFolder
        {
            $folder = $this->root;
            foreach ($this->segments($path) as $segment) {
                $child = $folder->children[$segment] ?? null;
                if (!$child instanceof ReplacementFolder) {
                    $child = $folder->newFolder($segment);
                }
                $folder = $child;
            }

            return $folder;
        }

        /** @return list<string> */
        private function segments(string $path): array
        {
            return array_values(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== ''));
        }
    }

    /** The appdata tree the backup goes into: nested SimpleFS folders, in memory. */
    final class ReplacementAppData implements \OCP\Files\IAppData
    {
        public ReplacementSimpleFolder $root;
        /** @var list<string> */
        public array $created = [];

        public function __construct()
        {
            $this->root = new ReplacementSimpleFolder('');
        }

        public function getFolder(string $name): \OCP\Files\SimpleFS\ISimpleFolder
        {
            if (!isset($this->root->folders[$name])) {
                throw new \OCP\Files\NotFoundException('no appdata folder ' . $name);
            }

            return $this->root->folders[$name];
        }

        public function newFolder(string $name): \OCP\Files\SimpleFS\ISimpleFolder
        {
            $folder = new ReplacementSimpleFolder($name);
            $this->root->folders[$name] = $folder;
            $this->created[] = $name;

            return $folder;
        }

        /** @return list<string> the ids of the backups taken, in the order they were created */
        public function backups(): array
        {
            $root = $this->root->folders['source-backups'] ?? null;

            return $root instanceof ReplacementSimpleFolder ? array_map('strval', array_keys($root->folders)) : [];
        }
    }

    /** One folder of that appdata tree; newFolder() is the nested half SimpleFS also offers. */
    final class ReplacementSimpleFolder implements \OCP\Files\SimpleFS\ISimpleFolder
    {
        /** @var array<string,ReplacementSimpleFolder> */
        public array $folders = [];
        /** @var array<string,ReplacementSimpleFile> */
        public array $files = [];

        public function __construct(private string $name)
        {
        }

        public function getName(): string
        {
            return $this->name;
        }

        public function fileExists(string $name): bool
        {
            return $this->fileAt($name) !== null;
        }

        public function getFile(string $name): \OCP\Files\SimpleFS\ISimpleFile
        {
            return $this->fileAt($name) ?? throw new \OCP\Files\NotFoundException('no backup file ' . $name);
        }

        /** @return list<\OCP\Files\SimpleFS\ISimpleFile> */
        public function getDirectoryListing(): array
        {
            return array_values($this->files);
        }

        public function newFile(string $name, $content = null): \OCP\Files\SimpleFS\ISimpleFile
        {
            $file = new ReplacementSimpleFile($name, (string)$content);
            $this->files[$name] = $file;

            return $file;
        }

        public function newFolder(string $name): \OCP\Files\SimpleFS\ISimpleFolder
        {
            $folder = new ReplacementSimpleFolder($name);
            $this->folders[$name] = $folder;

            return $folder;
        }

        public function getFolder(string $name): \OCP\Files\SimpleFS\ISimpleFolder
        {
            return $this->folders[$name] ?? throw new \OCP\Files\NotFoundException('no backup folder ' . $name);
        }

        private function fileAt(string $name): ?ReplacementSimpleFile
        {
            $folder = $this;
            $segments = array_values(array_filter(explode('/', $name), static fn (string $segment): bool => $segment !== ''));
            foreach ($segments as $index => $segment) {
                if ($index === count($segments) - 1) {
                    return $folder->files[$segment] ?? null;
                }
                $child = $folder->folders[$segment] ?? null;
                if ($child === null) {
                    return null;
                }
                $folder = $child;
            }

            return null;
        }
    }

    /** One file of a backup folder; putContent() is what restore() reads through getContent(). */
    final class ReplacementSimpleFile implements \OCP\Files\SimpleFS\ISimpleFile
    {
        public function __construct(private string $name, private string $content)
        {
        }

        public function getName(): string
        {
            return $this->name;
        }

        public function getContent(): string
        {
            return $this->content;
        }

        public function putContent($data): void
        {
            $this->content = (string)$data;
        }

        public function getETag(): string
        {
            return 'backup-etag-' . sha1($this->content);
        }
    }

}

namespace {
    use OCA\Pages\Db\Article;
    use OCA\Pages\Db\ArticleMapper;
    use OCA\Pages\Service\ArticleIndexService;
    use OCA\Pages\Service\ArticleReviewService;
    use OCA\Pages\Service\AuthorAreaService;
    use OCA\Pages\Service\AuthorDeltaService;
    use OCA\Pages\Service\BridgeClient;
    use OCA\Pages\Service\BridgeException;
    use OCA\Pages\Service\ConflictException;
    use OCA\Pages\Service\DeltaQueue;
    use OCA\Pages\Service\FakeSiteRegistry;
    use OCA\Pages\Service\FileService;
    use OCA\Pages\Service\FrontMatter;
    use OCA\Pages\Service\L10N;
    use OCA\Pages\Service\OrphanTransfer;
    use OCA\Pages\Service\ReviewStore;
    use OCA\Pages\Service\SiteRegistry;
    use OCA\Pages\Service\SiteTrees;
    use OCA\Pages\Controller\BuildController;
    use OCA\Pages\Controller\ReviewController;
    use OCP\Files\NotPermittedException;
    use Probe\FakeAppData;
    use Probe\ReplacementAppData;
    use Probe\ReplacementFiles;
    use Probe\FakeConfig;
    use Probe\FakeCrypto;
    use Probe\FakeGroupManager;
    use Probe\FakeLocking;
    use Probe\FakeLogger;
    use Probe\FakeRequest;
    use Probe\FakeUserManager;
    use Probe\FakeUserSession;
    use Probe\QueueConfig;
    use Probe\Trees;

    require_once __DIR__ . '/../../lib/Service/ConflictException.php';
    require_once __DIR__ . '/../../lib/Service/BridgeException.php';
    require_once __DIR__ . '/../../lib/Service/NotFoundException.php';
    // the real reader of online/ and pending/, driven by the fake bridge above
    require_once __DIR__ . '/../../lib/Service/SiteTrees.php';
    require_once __DIR__ . '/../../lib/Service/ReviewStore.php';
    // the whole-folder replacement and the folder-lock retry around it (section 37)
    require_once __DIR__ . '/../../lib/Service/SourceReplacementException.php';
    require_once __DIR__ . '/../../lib/Service/SourceReplacementService.php';
    require_once __DIR__ . '/../../lib/Service/ArticleReviewService.php';
    // the durable queue of failed author deltas, and the service that fills it
    require_once __DIR__ . '/../../lib/Service/DeltaQueue.php';
    require_once __DIR__ . '/../../lib/Service/AuthorDeltaService.php';
    // the real controller for the force passthrough (section 31)
    require_once __DIR__ . '/../../lib/Controller/BaseController.php';
    require_once __DIR__ . '/../../lib/Controller/BuildController.php';
    // the visibility endpoints, for the authoring gate of cancel() (section 33)
    require_once __DIR__ . '/../../lib/Controller/ReviewController.php';

    /**
     * The real ArticleIndexService, loaded under another name.
     *
     * The probe's own `OCA\Pages\Service\ArticleIndexService` is a three-line stand-in (the review
     * service only calls `register()`); section 36 has to exercise the real row builder. PHP cannot
     * load the real class beside that stand-in under the same name, so the source is loaded with its
     * class name changed and nothing else: the code under test is otherwise the file in lib/.
     */
    function loadRealArticleIndexService(): void
    {
        if (class_exists(\OCA\Pages\Service\ProbeRealArticleIndexService::class, false)) {
            return;
        }
        $source = (string)file_get_contents(__DIR__ . '/../../lib/Service/ArticleIndexService.php');
        $source = preg_replace('/\A<\?php\s*/', '', $source, 1) ?? $source;
        $source = str_replace('declare(strict_types=1);', '', $source);
        $renamed = preg_replace(
            '/\bclass\s+ArticleIndexService\b/',
            'class ProbeRealArticleIndexService',
            $source,
            1,
        );
        if (!is_string($renamed) || $renamed === $source) {
            throw new \RuntimeException('the article index could not be loaded under a probe name');
        }
        eval($renamed);
    }

    /** The rejection notification: recorded, never rendered. */
    final class FakeNotifications implements \OCP\Notification\IManager
    {
        /** @var list<array<string,mixed>> */
        public array $sent = [];

        public function createNotification(): \OCP\Notification\Notification
        {
            return new \OCP\Notification\Notification();
        }

        public function notify(\OCP\Notification\Notification $notification): void
        {
            $this->sent[] = $notification->data;
        }
    }

    /** The one URL the review service builds: a link back to the app. */
    final class FakeUrl implements \OCP\IURLGenerator
    {
        /** @param array<string,mixed> $params */
        public function linkToRouteAbsolute(string $route, array $params = []): string
        {
            return 'http://nextcloud.example/index.php/apps/pages/';
        }
    }

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
     * `trees` is the probe's helper around the **real** {@see SiteTrees}, which is handed the fake
     * bridge: every "is it live" question the store asks is answered by `GET /site/paths`.
     *
     * @return array{store:ReviewStore,appData:FakeAppData,locking:FakeLocking,files:FileService,trees:Trees,articles:ArticleMapper,bridge:BridgeClient,logger:FakeLogger,config:FakeConfig}
     */
    $build = function (?SiteRegistry $registry = null): array {
        $appData = new FakeAppData();
        $locking = new FakeLocking();
        $files = new FileService();
        $articles = new ArticleMapper();
        $bridge = new BridgeClient();
        $logger = new FakeLogger();
        $config = new FakeConfig();
        $registry ??= new FakeSiteRegistry();
        $trees = new Trees(new SiteTrees($registry, $files, $bridge, $logger), $bridge);
        $store = new ReviewStore(
            $appData,
            $locking,
            $registry,
            $trees->trees,
            $config,
            $articles,
            $bridge,
            $logger,
            new L10N(),
        );

        return compact('store', 'appData', 'locking', 'files', 'trees', 'articles', 'bridge', 'logger', 'config');
    };

    $document = static function (array $articles): string {
        return (string)json_encode(['version' => 4, 'entries' => $articles]);
    };

    /**
     * One review service over the fakes above, with its own store (so each workflow test starts from
     * an empty manifest and empty content directories).
     *
     * @return array{review:ArticleReviewService,store:ReviewStore,appData:FakeAppData,trees:Trees,articles:ArticleMapper,files:FileService,bridge:BridgeClient,areas:AuthorAreaService,index:ArticleIndexService,orphan:OrphanTransfer,notifications:FakeNotifications,logger:FakeLogger,config:FakeConfig,locking:FakeLocking,registry:FakeSiteRegistry}
     */
    $makeReview = function (?FakeSiteRegistry $registry = null) use ($build): array {
        $areas = new AuthorAreaService();
        $index = new ArticleIndexService();
        $orphan = new OrphanTransfer();
        $notifications = new FakeNotifications();
        $registry ??= new FakeSiteRegistry();
        ['store' => $store, 'appData' => $appData, 'trees' => $trees, 'articles' => $articles, 'files' => $files, 'bridge' => $bridge, 'logger' => $logger, 'config' => $config, 'locking' => $locking] = $build($registry);
        $review = new ArticleReviewService(
            $articles,
            $store,
            $files,
            $areas,
            $registry,
            $index,
            $bridge,
            new L10N(),
            new FakeUserManager(),
            $notifications,
            new FakeUrl(),
            $orphan,
            $locking,
        );

        return compact('review', 'store', 'appData', 'trees', 'articles', 'files', 'bridge', 'areas', 'index', 'orphan', 'notifications', 'logger', 'config', 'locking', 'registry');
    };

    echo "1. a missing manifest is the empty v4 manifest\n";
    ['store' => $store, 'appData' => $appData, 'locking' => $locking] = $build();
    $manifest = $store->read();
    $check('read() answers the documented shape', $manifest === ['version' => 4, 'entries' => []]);
    $check('exists() is false', $store->exists() === false);
    $check('state() of an unknown article is internal', $store->state('/tmp/a/one.md') === Article::STATE_INTERNAL);
    $check('entry() of an unknown article is []', $store->entry('tmp/a/one.md') === []);
    $check('pendingTargets()/onlineTargets()/counts() of a missing layout are empty', $store->pendingTargets() === []
        && $store->onlineTargets() === [] && $store->counts() === ['pending' => 0, 'online' => 0]);
    $check('the app\'s own appdata root is the only folder ever asked for', array_unique($appData->folderRequests) === ['/']);
    $check('location() names the appdata path', $store->location() === 'appdata_probe/pages/review.json');
    $check('a read takes no lock', $locking->acquired === [] && $locking->released === 0);
    $check('a write works without a site storage user (the manifest is in nobody\'s files)', (function () use ($build): bool {
        ['store' => $store, 'appData' => $appData] = $build(new FakeSiteRegistry(null));
        $store->setFields(['tmp/a/one.md'], ['owner' => 'a', 'downRequest' => ['at' => 5, 'sourceDeleted' => false]]);
        $decoded = json_decode($appData->content ?? '', true);

        return ($decoded['entries']['tmp/a/one.md']['downRequest']['at'] ?? 0) === 5;
    })());

    echo "2. setFields writes the v4 entry and syncs the cache\n";
    ['store' => $store, 'appData' => $appData, 'locking' => $locking, 'articles' => $articles] = $build();
    $articles->rows['/tmp/a/one.md'] = new Article('/tmp/a/one.md');
    $articles->rows['/index.md'] = new Article('/index.md', Article::STATE_EXTERNAL);
    $store->setFields(['/tmp/a/one.md'], ['owner' => 'a', 'downRequest' => ['at' => 111, 'sourceDeleted' => false], 'note' => '', 'decidedBy' => '']);
    $raw = $appData->content ?? '';
    $decoded = json_decode($raw, true);
    $check('the file was created', $raw !== '');
    $check('the document has version/entries only', is_array($decoded) && array_keys($decoded) === ['version', 'entries']);
    $check('version is 4', ($decoded['version'] ?? null) === 4);
    $entry = $decoded['entries']['tmp/a/one.md'] ?? null;
    $check('the entry carries the documented fields', is_array($entry)
        && array_keys($entry) === ['owner', 'candidateDigest', 'downRequest', 'decidedBy', 'note']);
    $check('a request that binds no candidate leaves the digest empty', ($entry['candidateDigest'] ?? 'x') === '');
    $check('the request is stored with its moment and its flag', ($entry['downRequest'] ?? null) === ['at' => 111, 'sourceDeleted' => false]);
    $check('no candidate, published, digest or state is written', is_array($entry)
        && !array_key_exists('candidate', $entry) && !array_key_exists('published', $entry)
        && !array_key_exists('digest', $entry) && !array_key_exists('state', $entry));
    $check('the file keeps a trailing newline', str_ends_with($raw, "\n"));
    $check('the file is indented with two spaces', str_contains($raw, "\n  \"entries\"") && !str_contains($raw, "\n    \"entries\""));
    $check('the article cache got the derived state and the request moment', $articles->calls === [[
        'path' => '/tmp/a/one.md', 'state' => 'requested', 'note' => '', 'submittedAt' => 111, 'publishedAt' => 0, 'revision' => '',
    ]], (string)json_encode($articles->calls));
    $check('the state is visible through both accessors', $store->state('/tmp/a/one.md') === 'requested'
        && $store->stateIn($store->read(), 'tmp/a/one.md') === 'requested');
    $check('the write held the app\'s lock for its key and released it', $locking->acquired === ['pages/review.json']
        && $locking->held() === [] && $locking->released === 1);

    echo "3. a second write only touches what differs\n";
    $before = count($articles->calls);
    $store->setFields(['tmp/a/one.md'], ['note' => 'changed']);
    $check('a real change is written once', count($articles->calls) === $before + 1 && $articles->calls[$before]['note'] === 'changed');
    $fileWrites = count($appData->writes);
    $store->setFields(['tmp/a/one.md'], ['note' => 'changed']);
    $check('a no-op decision still writes the manifest (brings the caches in step)', count($appData->writes) === $fileWrites + 1);
    $check('but the unchanged row is not written again', count($articles->calls) === $before + 1);
    $check('the entry kept its request and took the note', ($store->entry('tmp/a/one.md')['downRequest']['at'] ?? 0) === 111
        && ($store->entry('tmp/a/one.md')['note'] ?? '') === 'changed');

    echo "4. the state is derived from the two content directories\n";
    ['store' => $store, 'trees' => $trees] = $build();
    $check('no directory entry and no request: internal', $store->state('tmp/a/one.md') === Article::STATE_INTERNAL);
    $trees->publish('tmp/a/one.md', 111);
    $check('online/<target> makes it external', $store->state('tmp/a/one.md') === Article::STATE_EXTERNAL
        && $store->isLive('tmp/a/one.md'));
    $check('the online file\'s mtime is readable as its moment', $store->liveStamp('tmp/a/one.md') === 111);
    $trees->freeze('tmp/a/one.md', 222);
    $check('pending/<target> is the candidate: the state still follows online/, and the request is visible',
        $store->state('tmp/a/one.md') === Article::STATE_EXTERNAL && $store->isPending('tmp/a/one.md'));
    $check('the candidate moment is the pending file\'s own', $store->pendingStamp('tmp/a/one.md') === 222);
    $check('the direction is online', $store->intentIn($store->read(), 'tmp/a/one.md') === Article::INTENT_ONLINE);
    $store->setFields(['tmp/a/one.md'], ['owner' => 'a', 'downRequest' => ['at' => 333, 'sourceDeleted' => false]]);
    $trees->approve('tmp/a/one.md', 300);
    $check('a take-down request wins over the served version', $store->state('tmp/a/one.md') === Article::STATE_REQUESTED
        && $store->intentIn($store->read(), 'tmp/a/one.md') === Article::INTENT_OFFLINE);
    $store->setFields(['tmp/a/one.md'], ['downRequest' => null]);
    $check('clearing the request falls back to what the folder says', $store->state('tmp/a/one.md') === Article::STATE_EXTERNAL);
    $check('an unknown article is internal with no moment', $store->state('tmp/a/none.md') === Article::STATE_INTERNAL
        && $store->liveStamp('tmp/a/none.md') === 0 && $store->pendingStamp('tmp/a/none.md') === 0);

    echo "5. pendingTargets()/onlineTargets()/counts() read the directories\n";
    ['store' => $store, 'trees' => $trees, 'bridge' => $bridge] = $build();
    $trees->publish('tmp/b/z.md');
    $trees->publish('tmp/a/a.md');
    $trees->freeze('tmp/c/c.md');
    $check('onlineTargets are sorted and site-relative', $store->onlineTargets() === ['tmp/a/a.md', 'tmp/b/z.md']);
    $check('pendingTargets are sorted and site-relative', $store->pendingTargets() === ['tmp/c/c.md']);
    $check('counts() counts the two directories', $store->counts() === ['pending' => 1, 'online' => 2]);
    $asked = $bridge->snapshots;
    $store->refreshTrees();
    $check('refreshTrees() drops the cached read: the next question asks the bridge again',
        $store->counts() === ['pending' => 1, 'online' => 2] && $bridge->snapshots === $asked + 1,
        $asked . ' -> ' . $bridge->snapshots);

    echo "6. a replacement behind our back re-reads and re-applies the change\n";
    ['store' => $store, 'appData' => $appData, 'locking' => $locking, 'logger' => $logger] = $build();
    $store->setFields(['tmp/a/one.md'], ['owner' => 'a', 'downRequest' => ['at' => 111, 'sourceDeleted' => false]]);
    $appData->conflicts = 1;
    $store->setFields(['tmp/a/one.md'], ['decidedBy' => 'bob', 'note' => 'no']);
    $check('the retry applied the change', ($store->entry('tmp/a/one.md')['decidedBy'] ?? '') === 'bob');
    $check('and it kept what the other writer had written', ($store->entry('tmp/other/two.md')['owner'] ?? '') === 'other');
    $check('the lock was taken for each attempt and released', $locking->acquired === ['pages/review.json', 'pages/review.json']
        && $locking->held() === [] && $locking->released === 2);
    $check('nothing was logged about it', $logger->lines === []);
    // On a local storage the ETag follows the mtime in whole seconds, so a file replaced twice inside one
    // second keeps its ETag: the content comparison has to catch that one (see ReviewStore::write()).
    $appData->replacementKeepsEtag = true;
    $appData->conflicts = 1;
    $store->setFields(['tmp/a/one.md'], ['downRequest' => null, 'note' => 'no', 'decidedBy' => 'bob']);
    $check('a replacement that keeps the ETag is noticed too',
        array_key_exists('downRequest', $store->entry('tmp/a/one.md')) && $store->entry('tmp/a/one.md')['downRequest'] === null
        && ($store->entry('tmp/other/two.md')['owner'] ?? '') === 'other');

    echo "7. a manifest that keeps changing gives up with a message\n";
    ['store' => $store, 'appData' => $appData, 'locking' => $locking] = $build();
    $store->setFields(['tmp/a/one.md'], ['owner' => 'a']);
    $appData->conflicts = 99;
    try {
        $store->setFields(['tmp/a/one.md'], ['downRequest' => ['at' => 9, 'sourceDeleted' => false]]);
        $check('a permanent conflict throws', false);
    } catch (ConflictException $e) {
        $check('a permanent conflict throws a ConflictException', str_contains($e->getMessage(), '3 times in a row'));
    }
    $check('the lock is released even then', $locking->held() === []);
    $check('the other writer\'s document is untouched', ($store->entry('tmp/other/two.md')['owner'] ?? '') === 'other');

    echo "8. missing / empty / corrupt / unreadable files\n";
    ['store' => $store, 'appData' => $appData, 'locking' => $locking, 'logger' => $logger] = $build();
    $check('a missing file is not corrupt', $store->read() === ['version' => 4, 'entries' => []]
        && $store->isCorrupt() === false);
    $store->setFields(['tmp/a/first.md'], ['owner' => 'a']);
    $check('a missing file is created by the first write', json_decode($appData->content ?? '', true) !== null
        && $store->isCorrupt() === false);
    $appData->create('');
    $check('an empty file reads as the empty manifest', $store->read()['entries'] === []);
    $check('an empty file is not corrupt', $store->isCorrupt() === false);
    $store->setFields(['tmp/a/one.md'], ['owner' => 'a', 'downRequest' => ['at' => 5, 'sourceDeleted' => false]]);
    $check('an empty file is filled in by the first write', json_decode($appData->content ?? '', true) !== null
        && $store->state('tmp/a/one.md') === Article::STATE_REQUESTED);
    // a file whose bytes are only whitespace is the same "no manifest yet" as a zero-byte one
    $appData->create("  \n\t\n");
    $check('a whitespace-only file reads as the empty manifest and is not corrupt',
        $store->read()['entries'] === [] && $store->isCorrupt() === false);
    $store->setFields(['tmp/a/one.md'], ['owner' => 'a']);
    $check('and the first write fills that in too', json_decode($appData->content ?? '', true) !== null);
    $check('nothing was logged about blank files', $logger->lines === []);

    // a document whose bytes are there and are not JSON: the failure this probe exists for
    $corrupt = '{"version":4,"entries":{"tmp/a/one.md":{"owner":"a","downRequest":{"at":9';
    $appData->create($corrupt);
    $check('a corrupt file reads as the empty manifest', $store->read() === ['version' => 4, 'entries' => []]);
    $check('and it is reported as corrupt, not as empty', $store->isCorrupt() === true);
    $check('a corrupt file is reported as existing', $store->exists() === true);
    $check('the corruption is logged once, with the path and the size', (function () use ($logger, $corrupt): bool {
        $warning = $logger->lines[0] ?? [];

        return count($logger->lines) === 1
            && ($warning['level'] ?? '') === 'warning'
            && str_contains((string)($warning['message'] ?? ''), 'not valid JSON')
            && ($warning['context']['path'] ?? '') === 'appdata_probe/pages/review.json'
            && ($warning['context']['size'] ?? 0) === strlen($corrupt);
    })());
    $check('a write over a corrupt file is refused', (function () use ($store): bool {
        try {
            $store->setFields(['tmp/a/one.md'], ['owner' => 'a']);
        } catch (NotPermittedException $e) {
            return str_contains($e->getMessage(), 'not valid JSON');
        }

        return false;
    })());
    $check('the corrupt file was not touched', $appData->content === $corrupt);
    $check('its bytes were copied aside once, under the documented name', count($appData->sidelined) === 1
        && array_values($appData->sidelined) === [$corrupt]
        && str_starts_with((string)array_key_first($appData->sidelined), ReviewStore::CORRUPT_PREFIX));
    $check('a second refused write makes no second copy', (function () use ($store, $appData): bool {
        try {
            $store->setFields(['tmp/a/one.md'], ['owner' => 'a']);
        } catch (NotPermittedException) {
            // the refusal is what the check before it asserted; this one is about the evidence
        }

        return count($appData->sidelined) === 1 && $appData->content !== null;
    })());
    $check('and the corruption is not logged a second time', count($logger->lines) === 1);
    $check('the lock is released after the refusal', $locking->held() === []);
    // the repair: a valid document, which is what a hand repair (or a restore) leaves behind
    $appData->create($document(['tmp/a/one.md' => ['owner' => 'a', 'downRequest' => ['at' => 9, 'sourceDeleted' => false]]]));
    $check('a repaired manifest is not corrupt any more', $store->state('tmp/a/one.md') === Article::STATE_REQUESTED
        && $store->isCorrupt() === false);
    $store->setFields(['tmp/a/one.md'], ['decidedBy' => 'bob']);
    $check('and writes work again', ($store->entry('tmp/a/one.md')['decidedBy'] ?? '') === 'bob');
    $check('the copy aside stays as the evidence', count($appData->sidelined) === 1);
    $appData->create('[]');
    $check('a JSON array reads as the empty manifest', $store->read()['entries'] === []);
    $check('a JSON array is not corruption (it can hold no decision)', $store->isCorrupt() === false);
    $store->setFields(['tmp/a/one.md'], ['owner' => 'a']);
    $check('a JSON array is replaced by a document as well', json_decode($appData->content ?? '', true) !== null);
    $appData->readError = 'permission denied';
    $check('an unreadable file still answers read()', $store->read()['entries'] === []);
    $check('a read failure is not a corruption (nothing is known about the bytes)', $store->isCorrupt() === false);
    $check('but a write is refused instead of wiping it', (function () use ($store): bool {
        try {
            $store->setFields(['tmp/a/one.md'], ['owner' => 'a']);
        } catch (NotPermittedException $e) {
            return str_contains($e->getMessage(), 'permission denied');
        }

        return false;
    })());
    $check('and the lock is released after that refusal', $locking->held() === []);

    echo "9. a hand-edited file cannot break the readers\n";
    ['store' => $store, 'appData' => $appData] = $build();
    $appData->create((string)json_encode([
        'version' => 3,
        'entries' => [
            '../etc/passwd' => ['state' => 'external'],
            '/tmp/a/one.md' => ['state' => 'External', 'owner' => ' a ', 'note' => ['oops'],
                'candidate' => ['at' => 5, 'digest' => 'x'], 'downRequest' => ['at' => 'nonsense'], 'published' => ['at' => 0]],
            'tmp/a/two.md' => 'not an entry',
            'tmp/a/three.md' => ['state' => 'requested', 'decidedBy' => 7, 'intent' => 'online'],
            'tmp/a/four.md' => ['owner' => 'a', 'published' => ['at' => 9, 'digest' => 'ABCDEF']],
            'tmp/a/five.md' => ['owner' => 'a', 'downRequest' => ['at' => 12, 'sourceDeleted' => true]],
        ],
        'published' => ['tmp/a/one.md' => ['owner' => 'a']],
    ]));
    $manifest = $store->read();
    $check('a path with .. is dropped', !isset($manifest['entries']['../etc/passwd']));
    $check('a leading slash is normalised away', isset($manifest['entries']['tmp/a/one.md']));
    $check('the retired snapshots are not part of the entry', !array_key_exists('candidate', $manifest['entries']['tmp/a/one.md'])
        && !array_key_exists('published', $manifest['entries']['tmp/a/four.md'])
        && array_key_exists('downRequest', $manifest['entries']['tmp/a/one.md'])
        && $manifest['entries']['tmp/a/one.md']['downRequest'] === null);
    $check('an entry whose request is not usable reads as internal', $store->state('tmp/a/one.md') === Article::STATE_INTERNAL);
    $check('a non-string note reads as empty', ($store->entry('tmp/a/one.md')['note'] ?? 'x') === '');
    $check('the owner is trimmed', ($store->entry('tmp/a/one.md')['owner'] ?? '') === 'a');
    $check('an entry that is not an object is dropped', !isset($manifest['entries']['tmp/a/two.md']));
    $check('a non-string decider reads as its scalar form', ($store->entry('tmp/a/three.md')['decidedBy'] ?? '') === '7');
    $check('a legacy intent without a request is not a pending request', $store->state('tmp/a/three.md') === Article::STATE_INTERNAL
        && $store->intentIn($manifest, 'tmp/a/three.md') === '');
    $check('a down request is the one state fact that survives, with its flag',
        ReviewStore::downRequestOf($manifest, 'tmp/a/five.md') === ['at' => 12, 'sourceDeleted' => true]
        && $store->state('tmp/a/five.md') === Article::STATE_REQUESTED);
    $check('and the entry spells it out', $manifest['entries']['tmp/a/five.md']['downRequest'] === ['at' => 12, 'sourceDeleted' => true]);

    echo "10. only the v4 sections are read: the pre-v2 shapes are ignored\n";
    ['store' => $store, 'appData' => $appData] = $build();
    $appData->create((string)json_encode([
        'version' => 1,
        'articles' => ['_posts/a/one.md' => ['state' => 'external', 'owner' => 'a']],
        'published' => ['_posts/a/one.md' => ['owner' => 'a', 'revision' => 'r1']],
    ]));
    $legacy = $store->read();
    $check('a document whose decisions sit under "articles" reads as the empty manifest', $legacy['version'] === 1
        && $legacy['entries'] === []);
    $check('and its retired published list is not read', array_keys($legacy) === ['version', 'entries']);
    $store->setFields(['tmp/a/other.md'], ['owner' => 'a']);
    $rewritten = json_decode($appData->content ?? '', true);
    $check('the next write stores the v4 sections only', is_array($rewritten)
        && array_keys($rewritten) === ['version', 'entries'] && ($rewritten['version'] ?? 0) === 4);
    $check('and it does not resurrect what was not read', !isset($rewritten['entries']['_posts/a/one.md'])
        && ($rewritten['entries']['tmp/a/other.md']['owner'] ?? '') === 'a');

    echo "11. the manifest is sorted, so a diff shows the change\n";
    ['store' => $store, 'appData' => $appData] = $build();
    foreach (['tmp/a/z.md', 'tmp/a/a.md', 'tmp/a/m.md'] as $path) {
        $store->setFields([$path], ['owner' => 'a']);
    }
    $raw = $appData->content ?? '';
    $check('the entries map is sorted by key', strpos($raw, 'tmp/a/a.md') < strpos($raw, 'tmp/a/m.md')
        && strpos($raw, 'tmp/a/m.md') < strpos($raw, 'tmp/a/z.md'));

    echo "12. the article cache is repaired on the next write\n";
    ['store' => $store, 'appData' => $appData, 'articles' => $articles] = $build();
    // a row from before the manifest existed: a state of its own, and stamps the entry will not carry
    $articles->rows['/tmp/a/one.md'] = new Article('/tmp/a/one.md', Article::STATE_EXTERNAL, 'old note', 5, 999, 'oldrev');
    $store->setFields(['tmp/a/one.md'], ['owner' => 'a', 'downRequest' => ['at' => 111, 'sourceDeleted' => false]]);
    $row = $articles->rows['/tmp/a/one.md'];
    $check('the drifted row was written back from the folders', $row->state === 'requested' && $row->note === null);
    $check('the request moment came from the request', $row->submittedAt === 111);
    $check('the publish stamps the entry does not carry are kept', $row->publishedAt === 999 && $row->revision === 'oldrev');
    $row->state = 'external';   // somebody edited the table behind the app's back
    $store->setFields(['tmp/a/one.md'], ['note' => 'n']);
    $check('the next write repairs the drifted row too', $articles->rows['/tmp/a/one.md']->state === 'requested');
    $check('and the stamps are still not cleared', $articles->rows['/tmp/a/one.md']->publishedAt === 999);
    $writes = count($articles->calls);
    $store->setFields(['tmp/a/one.md'], ['note' => 'n']);
    $check('an in-step row is never rewritten', count($articles->calls) === $writes);

    echo "13. the lock: one writer at a time, and never released unheld\n";
    ['store' => $store, 'appData' => $appData, 'locking' => $locking] = $build();
    $locking->busy = true;   // another request is writing right now
    try {
        $store->setFields(['tmp/a/one.md'], ['owner' => 'a']);
        $check('a busy lock is refused', false);
    } catch (ConflictException $e) {
        $check('a busy lock answers the app\'s own conflict', str_contains($e->getMessage(), 'being written right now'));
    }
    $check('and nothing was written', $appData->content === null);
    $check('and no lock was taken or released', $locking->acquired === [] && $locking->released === 0 && $locking->held() === []);
    $locking->busy = false;
    $check('the same key is never held twice (the provider is not recursive)', (function () use ($store, $locking): bool {
        $store->setFields(['tmp/a/one.md'], ['owner' => 'a']);
        $store->setFields(['tmp/a/two.md'], ['owner' => 'a']);

        return $locking->held() === [] && count($locking->acquired) === 2;
    })());

    echo "14. the manifest is read from appdata only\n";
    ['store' => $store, 'appData' => $appData, 'locking' => $locking] = $build(new FakeSiteRegistry(null));
    $appData->create($document(['tmp/a/one.md' => [
        'owner' => 'a', 'downRequest' => ['at' => 999, 'sourceDeleted' => false], 'decidedBy' => 'bob', 'note' => '',
    ]]));
    $writes = count($appData->writes);
    $check('read() answers the document the appdata file holds', $store->state('tmp/a/one.md') === Article::STATE_REQUESTED);
    $check('the appdata folder is the only place a read asks for', array_unique($appData->folderRequests) === ['/']);
    $check('a read takes no lock and writes nothing', $locking->acquired === [] && count($appData->writes) === $writes);
    $check('no site storage user is needed to read the manifest', $store->exists() === true);

    echo "21. a decision is recorded even when the cache cannot follow it\n";
    /*
     * The manifest is the truth and is written first; `pages_articles` is its projection. A failing
     * projection used to throw, so the controller answered 500 for a decision that was already in
     * force - and a retry answered 409 ("nothing is pending"), leaving the user unable to tell whether
     * the first attempt worked. The write now stands, the failure is logged and reported, and the next
     * write syncs the whole manifest, so it repairs the rows of this one.
     */
    ['store' => $store, 'appData' => $appData, 'articles' => $articles, 'logger' => $logger] = $build();
    $articles->rows['/tmp/a/one.md'] = new Article('/tmp/a/one.md');
    $store->setFields(['tmp/a/one.md'], ['owner' => 'a']);
    $check('a write that synced reports no cache failure', $store->lastCacheSyncFailed() === null);
    $articles->failReads = true;   // the cache is what goes wrong this time
    $thrown = false;
    try {
        $store->setFields(['tmp/a/one.md'], ['downRequest' => ['at' => 7, 'sourceDeleted' => false]]);
    } catch (\Throwable $e) {
        $thrown = true;
    }
    $recorded = $store->read();
    $check('a cache failure does not throw', !$thrown);
    $check('the decision is in the manifest and is what the file holds',
        ($recorded['entries']['tmp/a/one.md']['downRequest']['at'] ?? 0) === 7
        && (json_decode($appData->content ?? '', true)['entries']['tmp/a/one.md']['downRequest']['at'] ?? 0) === 7);
    $check('the store reports why the cache is behind', $store->lastCacheSyncFailed() === 'the article cache cannot be read',
        var_export($store->lastCacheSyncFailed(), true));
    $check('the failure is logged as an error, with the exception',
        count($logger->errors()) === 1
        && str_contains((string)($logger->errors()[0] ?? ''), 'article cache could not be updated'));
    $check('the cache row is still the one from before (that is what "behind" means)',
        $articles->rows['/tmp/a/one.md']->state === Article::STATE_INTERNAL);
    $articles->failReads = false;
    $store->setFields(['tmp/a/one.md'], ['downRequest' => ['at' => 8, 'sourceDeleted' => false]]);
    $check('the next write repairs the row and clears the report',
        $store->lastCacheSyncFailed() === null && $articles->rows['/tmp/a/one.md']->state === Article::STATE_REQUESTED
        && $articles->rows['/tmp/a/one.md']->submittedAt === 8);
    // the other half of the rule: a manifest that is not written still fails the action
    $appData->conflicts = 99;
    $thrown = false;
    try {
        $store->setFields(['tmp/a/one.md'], ['downRequest' => null]);
    } catch (ConflictException) {
        $thrown = true;
    }
    $check('a manifest write that never happens still throws, and the file keeps its entry', $thrown
        && ($store->read()['entries']['tmp/a/one.md']['downRequest']['at'] ?? 0) === 8);

    echo "22. the front-matter writer quotes what the parser cannot read back\n";
    /*
     * `FrontMatter::update()` is what keeps a Jekyll post readable, and what it writes is read by two
     * readers: Jekyll's YAML and this app's own `parse()`. A value written bare that either of them
     * takes for syntax is a file that says something the author did not write - a line break split the
     * key off its value, and a leading `*`, `&`, `!`, `|`, `#`, `@` or `` ` `` became an alias, an
     * anchor, a tag, a block scalar or a comment. What stays bare is what both readers read as the
     * same text; everything else is double-quoted, and the block is parsed back before the file is
     * written: a value that would not survive is refused (a 400 through the controller, see
     * `saveArticle()`), never stored wrong.
     *
     * "Text" is not "ASCII": a Chinese title is text to both readers, and so is a date - a *bare*
     * `2026-10-31` is resolved to a date by YAML 1.1 and a quoted one to a string, so quoting a date
     * is a behaviour change for Jekyll (it sorts and formats on the type) and not just a different
     * spelling of the same line.
     */
    require_once __DIR__ . '/../../lib/Service/FrontMatter.php';
    /** A document with the given block, as the article endpoints read and write them. */
    $fm = static fn (string $block): string => "---\n" . $block . "\n---\n\nBody.\n";
    /** The front matter a written document holds. */
    $fmRead = static fn (string $doc): array => FrontMatter::parse($doc)['data'];
    /** The one line that assigns a key, as the file holds it. */
    $fmLine = static function (string $doc, string $key): string {
        foreach (explode("\n", $doc) as $line) {
            if (str_starts_with($line, $key . ':')) {
                return $line;
            }
        }

        return '';
    };

    $doc = FrontMatter::update($fm('title: old'), ['title' => "a\nb"]);
    $check('a value with a line break is one quoted line, not two', $fmLine($doc, 'title') === 'title: "a\nb"',
        $fmLine($doc, 'title'));
    $check('and the break is still in the value after a read-back', ($fmRead($doc)['title'] ?? null) === "a\nb");

    $indicators = ['*x*', '#x', '|x', '&x', '!x', '@x', '`x', '-x', '%x', '[x]', '{x}', '"x', "'x", '?x', ':x', 'a: b'];
    $bare = [];
    $changed = [];
    foreach ($indicators as $value) {
        $written = FrontMatter::update($fm('title: old'), ['title' => $value]);
        if (!str_starts_with($fmLine($written, 'title'), 'title: "')) {
            $bare[] = $value;
        }
        if (($fmRead($written)['title'] ?? null) !== $value) {
            $changed[] = $value;
        }
    }
    $check('a value a YAML indicator would turn into syntax is quoted, and reads back as text',
        $bare === [] && $changed === [],
        'bare: ' . implode(' ', $bare) . ' / changed: ' . implode(' ', $changed));

    $doc = FrontMatter::update($fm('title: old'), ['title' => 'hello world']);
    $check('a plain value stays bare, so the block keeps the shape it had', $fmLine($doc, 'title') === 'title: hello world');

    $doc = FrontMatter::update($fm('title: old'), [
        'date' => '2026-05-01',
        'tags' => 'one two',
        'categories' => ['guides', 'notes'],
        'excerpt' => 'A fresh excerpt.',
    ]);
    $check('a day, a space-separated tag line, an inline list and a sentence keep their documented spelling',
        $fmLine($doc, 'date') === 'date: 2026-05-01'
        && $fmLine($doc, 'tags') === 'tags: one two'
        && $fmLine($doc, 'categories') === 'categories: ["guides", "notes"]'
        && $fmLine($doc, 'excerpt') === 'excerpt: A fresh excerpt.');

    $before = $fm("layout: post\nmy_custom_key: keep me\npermalink: /probe/\ntitle: old");
    $after = FrontMatter::update($before, ['title' => 'New title']);
    $check('a key nobody named keeps its line, byte for byte',
        str_contains($after, "layout: post\nmy_custom_key: keep me\npermalink: /probe/\ntitle: New title"), $after);

    $value = "C:\\tmp\\x\tend";
    $doc = FrontMatter::update($fm('title: old'), ['title' => $value]);
    $check('a backslash and a tab are escaped and read back unchanged', ($fmRead($doc)['title'] ?? null) === $value,
        $fmLine($doc, 'title'));

    $doc = FrontMatter::update($fm('title: old'), ['title' => 'Release #2']);
    $check('a `#` inside a quoted value is content, not a comment', ($fmRead($doc)['title'] ?? null) === 'Release #2',
        $fmLine($doc, 'title'));
    $check('a comment after a quoted value is still a comment', ($fmRead("---\ntitle: \"a #b\" # note\n---\n")['title'] ?? null) === 'a #b');
    $check('an apostrophe in a plain value still lets the comment be cut', ($fmRead("---\ntitle: Author's note # by bob\n---\n")['title'] ?? null) === "Author's note");

    $doc = FrontMatter::update($fm('title: old'), ['categories' => ['*x*', 'plain']]);
    $check('every category is quoted, so a slash in one is never YAML syntax',
        $fmLine($doc, 'categories') === 'categories: ["*x*", "plain"]'
        && ($fmRead($doc)['categories'] ?? null) === ['*x*', 'plain']);

    // The other direction of the same rule: a value that is only text is written as it is, whatever
    // script it is in. Quoting a Chinese title would still be valid YAML, but it would be a line the
    // author did not write, in a file the app has nothing to say about.
    $doc = FrontMatter::update($fm('title: old'), ['title' => '中文标题']);
    $check('non-ASCII text is bare and reads back as itself',
        $fmLine($doc, 'title') === 'title: 中文标题'
        && ($fmRead($doc)['title'] ?? null) === '中文标题', $fmLine($doc, 'title'));

    $doc = FrontMatter::update($fm('title: old'), ['categories' => ['中文']]);
    $check('a Chinese category is quoted like every other one and still reads back',
        $fmLine($doc, 'categories') === 'categories: ["中文"]'
        && ($fmRead($doc)['categories'] ?? null) === ['中文'], $fmLine($doc, 'categories'));

    // A date is the case where the quotes are not merely ugly: YAML 1.1 resolves a bare `2026-10-31`
    // to a date and a quoted one to a string, and Jekyll sorts and formats on that type.
    $doc = FrontMatter::update($fm('title: old'), ['modified_date' => '2026-10-31']);
    $check('a date stays bare, so YAML keeps reading it as a date instead of a string',
        $fmLine($doc, 'modified_date') === 'modified_date: 2026-10-31'
        && ($fmRead($doc)['modified_date'] ?? null) === '2026-10-31', $fmLine($doc, 'modified_date'));

    $iso = '2026-05-01T10:00:00Z';
    $doc = FrontMatter::update($fm('title: old'), ['date' => $iso]);
    $check('an ISO timestamp is bare (`:` may live in a value) and comes back unchanged',
        $fmLine($doc, 'date') === 'date: ' . $iso
        && ($fmRead($doc)['date'] ?? null) === $iso, $fmLine($doc, 'date'));

    // The stamp the server writes is `date('Y-m-d H:i:s O')`, and YAML 1.1 does not resolve `+0800`
    // (its timestamp rule wants `+08:00` or `Z`), so this one is a string to Jekyll either way: bare
    // only means the file keeps the line the panel shows.
    $stamp = '2026-10-31 15:00:00 +0800';
    $doc = FrontMatter::update($fm('title: old'), ['modified_date' => $stamp]);
    $check('the server stamp is bare and reads back as the same string',
        $fmLine($doc, 'modified_date') === 'modified_date: ' . $stamp
        && ($fmRead($doc)['modified_date'] ?? null) === $stamp, $fmLine($doc, 'modified_date'));

    // What is still quoted: everything that carries a meaning of its own to one of the readers.
    $doc = FrontMatter::update($fm('title: old'), ['title' => '中文 # 注释']);
    $check('a ` #` still forces quotes, in Chinese text as anywhere else',
        $fmLine($doc, 'title') === 'title: "中文 # 注释"'
        && ($fmRead($doc)['title'] ?? null) === '中文 # 注释', $fmLine($doc, 'title'));

    $doc = FrontMatter::update($fm('title: old'), ['title' => 'a: b']);
    $check('a `: ` still forces quotes, so the value cannot become a nested mapping',
        $fmLine($doc, 'title') === 'title: "a: b"'
        && ($fmRead($doc)['title'] ?? null) === 'a: b', $fmLine($doc, 'title'));

    // A colon is allowed *in* a value (a timestamp is spelled with it), so the other ways a value can
    // be read as something it is not are pinned here: a key with no value, a base-60 and an
    // underscore-separated number (both ints to YAML), a broken timestamp, and the two space edges.
    $shapes = ['title:', '2:30', '1_000', '<<', '2026-05-01 10: 00', 'x ', ' x', "x\ty", "x\x07y"];
    $bare = [];
    $changed = [];
    foreach ($shapes as $value) {
        $written = FrontMatter::update($fm('title: old'), ['title' => $value]);
        if (!str_starts_with($fmLine($written, 'title'), 'title: "')) {
            $bare[] = $value;
        }
        if (($fmRead($written)['title'] ?? null) !== $value) {
            $changed[] = $value;
        }
    }
    $check('a key-with-no-value, a number-like or a space-edged value keeps its quotes and reads back',
        $bare === [] && $changed === [],
        'bare: ' . implode(' ', $bare) . ' / changed: ' . implode(' ', $changed));

    $doc = FrontMatter::update("Body only.\n", ['title' => "a\nb"]);
    $check('a document without a block gets one, quoted value and all',
        str_starts_with($doc, "---\ntitle: \"a\\nb\"\n") && ($fmRead($doc)['title'] ?? null) === "a\nb", $doc);

    $refused = '';
    try {
        // the inline list is split on commas by the parser, so an item carrying one cannot come back:
        // the write is refused instead of storing a list that reads as two items
        FrontMatter::update($fm('title: old'), ['topics' => ['a,b']]);
    } catch (\InvalidArgumentException $e) {
        $refused = $e->getMessage();
    }
    $check('a value that would not read back is refused, naming the key',
        str_contains($refused, 'topics') && str_contains($refused, 'front matter'), $refused);

    echo "23. the sticky initialised flag answers when the bridge cannot\n";
    /*
     * `SiteController::initialised()` asks the bridge, which is the authority, and reads this flag
     * only when that call throws. The flag therefore has to be a plain appconfig bit with three
     * properties: unset on a fresh install (a bridge that is down must not lock fields for a
     * repository nobody ever made), idempotent on every mark (each save, commit and status call goes
     * through it), and cleared by the one operation that deletes the repository - the reset. Only
     * `'1'` counts, so a hand-edited value can never lock the two fields by accident.
     */
    /** A real registry wired to the fakes: the flag lives in appconfig, nothing else of it is used. */
    $makeRegistry = static fn (FakeConfig $config): SiteRegistry => new SiteRegistry(
        $config,
        new FakeCrypto(),
        new L10N(),
        new FakeUserManager(),
    );

    $check('a fresh install has no initialised flag', $makeRegistry(new FakeConfig())->isRepoInitialised() === false);

    $flagConfig = new FakeConfig();
    $flagRegistry = $makeRegistry($flagConfig);
    $flagRegistry->markRepoInitialised();
    $check(
        'the mark stores `1` under the documented key',
        $flagRegistry->isRepoInitialised() === true
            && ($flagConfig->appValues['pages/repo_initialised'] ?? '') === '1'
            && SiteRegistry::KEY_REPO_INITIALISED === 'repo_initialised',
        var_export($flagConfig->appValues, true),
    );
    $check('and that is one write', $flagConfig->writes === ['pages/repo_initialised'],
        var_export($flagConfig->writes, true));
    $flagRegistry->markRepoInitialised();
    $check('marking an initialised repository writes nothing again',
        $flagConfig->writes === ['pages/repo_initialised'], var_export($flagConfig->writes, true));

    $handEdited = new FakeConfig();
    $handEdited->appValues['pages/repo_initialised'] = 'true';
    $check('only `1` is a yes: a hand-edited value does not lock the fields',
        $makeRegistry($handEdited)->isRepoInitialised() === false);

    $flagRegistry->resetConfiguration();
    $check(
        'the reset clears the flag (the only place allowed to)',
        $flagRegistry->isRepoInitialised() === false
            && !array_key_exists('pages/repo_initialised', $flagConfig->appValues),
    );

    echo "24. clear() empties the manifest and the article cache\n";
    /*
     * The sync that follows a write only updates the rows its manifest names - `pages_articles` also
     * holds the site's own posts, which have no manifest entry at all - so an empty document is not a
     * truncation there. The full reset is the one operation that must leave no cached row behind, and
     * that is the store's own job (`PagesReset` no longer deletes the table a second time).
     */
    ['store' => $store, 'articles' => $articles] = $build();
    $articles->rows['/tmp/a/one.md'] = new Article('/tmp/a/one.md');
    $articles->rows['/_posts/2026-01-01-x.md'] = new Article('/_posts/2026-01-01-x.md', Article::STATE_EXTERNAL);
    $store->setFields(['tmp/a/one.md'], ['owner' => 'a', 'downRequest' => ['at' => 5, 'sourceDeleted' => false]]);
    $check(
        'the entry and its cache row exist before the clear',
        ($store->read()['entries'] ?? []) !== [] && isset($articles->rows['/tmp/a/one.md']),
    );
    $store->clear();
    $cleared = $store->read();
    $check('the manifest is the empty document again', $cleared === ['version' => 4, 'entries' => []],
        var_export($cleared, true));
    $check('clear() empties pages_articles itself, the site posts included', $articles->rows === [],
        var_export(array_keys($articles->rows), true));

    echo "25. the review workflow: the folders speak, the manifest only carries what they cannot\n";
    /*
     * The contract of the directory-derived model, asserted end to end through
     * ArticleReviewService: an online request freezes a candidate and records **only its digest**;
     * an approval publishes it and writes nothing else (online/<target> is the record); a rejection
     * discards the candidate and clears the digest; a take-down removes the site's copy and forgets
     * the entry. Only the candidate's binding, an offline request and a reviewer's trail reach the
     * manifest.
     */
    ['review' => $review, 'store' => $store, 'appData' => $appData, 'trees' => $trees, 'articles' => $articles,
        'files' => $files, 'bridge' => $bridge, 'notifications' => $notifications, 'orphan' => $orphan] = $makeReview();
    $articles->rows['/tmp/a/one.md'] = new Article('/tmp/a/one.md', Article::STATE_INTERNAL, null, 0, 0, '', 'a');
    // the approval validates the source file exists, so the author's post has to be there
    $files->authorFiles['a:Pages/one.md'] = "# one\n";
    // what the bridge does to the two folders, replayed by the fake bridge
    $bridge->onOp = static function (string $op, string $target) use ($trees): void {
        if ($op === 'candidate') {
            $trees->freeze($target, 111);
        } elseif ($op === 'publish') {
            $trees->approve($target, 222);
        } elseif ($op === 'discard') {
            $trees->drop($target);
        } elseif ($op === 'take-down') {
            $trees->takeDown($target);
        }
    };

    $answer = $review->request('a', 'tmp/a/one.md', 'online', false);
    $check('request(online) freezes the candidate through the bridge', $bridge->ops === [
        ['op' => 'candidate', 'uid' => 'a', 'target' => 'tmp/a/one.md'],
    ], (string)json_encode($bridge->ops));
    $check('and answers the digest the bridge froze', ($answer['candidateDigest'] ?? '') === $bridge->candidateDigest);
    $check('and writes only the candidate binding into the manifest',
        ($store->entry('tmp/a/one.md')['candidateDigest'] ?? '') === $bridge->candidateDigest
        && ReviewStore::downRequestOf($store->read(), 'tmp/a/one.md') === null,
        (string)$appData->content);
    $check('the candidate is the pending file, and the intent is online', $store->isPending('tmp/a/one.md')
        && $store->intentIn($store->read(), 'tmp/a/one.md') === Article::INTENT_ONLINE);

    $bridge->ops = [];
    $answer = $review->decide('bob', 'tmp/a/one.md', 'approve', '');
    $check('decide(approve) publishes the frozen candidate and sends its digest', $bridge->ops === [
        ['op' => 'publish', 'uid' => 'a', 'target' => 'tmp/a/one.md', 'digest' => $bridge->candidateDigest],
    ], (string)json_encode($bridge->ops));
    $check('and writes no decision of its own into the manifest',
        ($store->entry('tmp/a/one.md')['candidateDigest'] ?? '') === $bridge->candidateDigest
        && ReviewStore::downRequestOf($store->read(), 'tmp/a/one.md') === null
        && ($store->entry('tmp/a/one.md')['decidedBy'] ?? '') === '');
    $check('the folder now says the article is live, and nothing waits', $store->isLive('tmp/a/one.md')
        && !$store->isPending('tmp/a/one.md') && $store->state('tmp/a/one.md') === Article::STATE_EXTERNAL);

    // an update request on a live page, refused: the candidate goes, the served version stays
    $review->request('a', 'tmp/a/one.md', 'online', false);
    $bridge->ops = [];
    $answer = $review->decide('bob', 'tmp/a/one.md', 'reject', 'not this version');
    $check('decide(reject) discards the candidate', $bridge->ops === [
        ['op' => 'discard', 'uid' => 'a', 'target' => 'tmp/a/one.md'],
    ], (string)json_encode($bridge->ops));
    $check('and clears the digest of the candidate it discarded',
        ($store->entry('tmp/a/one.md')['candidateDigest'] ?? 'x') === ''
        && ReviewStore::downRequestOf($store->read(), 'tmp/a/one.md') === null,
        (string)$appData->content);
    $check('the page the site serves is untouched', $store->isLive('tmp/a/one.md') && !$store->isPending('tmp/a/one.md'));
    $check('the author was told why', count($notifications->sent) === 1
        && ($notifications->sent[0]['subject'] ?? '') === \OCA\Pages\Notification\Notifier::SUBJECT_REJECTED,
        (string)json_encode($notifications->sent));
    $check('the refusal records the reviewer and the note on the entry',
        ($store->entry('tmp/a/one.md')['decidedBy'] ?? '') === 'bob'
        && ($store->entry('tmp/a/one.md')['note'] ?? '') === 'not this version',
        (string)$appData->content);

    // the take-offline request: the one direction with a manifest record of its own
    $bridge->ops = [];
    $review->request('a', 'tmp/a/one.md', 'offline', false);
    $check('request(offline) calls no bridge operation', $bridge->ops === []);
    $entry = $store->entry('tmp/a/one.md');
    $check('and records the take-down request, with the source kept', ($entry['downRequest']['sourceDeleted'] ?? null) === false
        && ($entry['downRequest']['at'] ?? 0) > 0, (string)json_encode($entry));
    $check('the derived state is requested, while the site still serves the page',
        $store->state('tmp/a/one.md') === Article::STATE_REQUESTED && $store->isLive('tmp/a/one.md'));

    $bridge->ops = [];
    $review->decide('bob', 'tmp/a/one.md', 'reject', 'keep it up');
    $check('refusing a take-off calls no bridge operation and writes no site change', $bridge->ops === [] && $store->isLive('tmp/a/one.md'));
    $check('and clears the request while recording the reviewer',
        array_key_exists('downRequest', $store->entry('tmp/a/one.md')) && $store->entry('tmp/a/one.md')['downRequest'] === null
        && ($store->entry('tmp/a/one.md')['decidedBy'] ?? '') === 'bob');

    // the same request, approved: the page leaves the site and the entry is cleared
    $review->request('a', 'tmp/a/one.md', 'offline', false);
    $bridge->ops = [];
    $review->decide('bob', 'tmp/a/one.md', 'approve', '');
    $check('decide(approve) on a take-off removes the site\'s copy', $bridge->ops === [
        ['op' => 'take-down', 'uid' => 'a', 'target' => 'tmp/a/one.md'],
    ], (string)json_encode($bridge->ops));
    $check('the page is internal again and the request is gone', !$store->isLive('tmp/a/one.md')
        && $store->state('tmp/a/one.md') === Article::STATE_INTERNAL
        && array_key_exists('downRequest', $store->entry('tmp/a/one.md'))
        && $store->entry('tmp/a/one.md')['downRequest'] === null);

    // a first publication whose source was deleted: approving forgets the entry entirely
    $articles->rows['/tmp/a/gone.md'] = new Article('/tmp/a/gone.md', Article::STATE_INTERNAL, null, 0, 0, '', 'a');
    $trees->publish('tmp/a/gone.md', 100);
    $store->setFields(['tmp/a/gone.md'], ['owner' => 'a', 'downRequest' => ['at' => 5, 'sourceDeleted' => true], 'note' => '', 'decidedBy' => '']);
    $bridge->ops = [];
    $review->decide('bob', 'tmp/a/gone.md', 'approve', '');
    $check('a take-off of an already deleted source forgets the entry', $bridge->ops === [
        ['op' => 'take-down', 'uid' => 'a', 'target' => 'tmp/a/gone.md'],
    ] && $store->entry('tmp/a/gone.md') === [] && !$store->isLive('tmp/a/gone.md'));

    // the reviewer's direct take-down: entry gone, page gone, nothing pending
    $articles->rows['/tmp/a/direct.md'] = new Article('/tmp/a/direct.md', Article::STATE_INTERNAL, null, 0, 0, '', 'a');
    $trees->publish('tmp/a/direct.md', 100);
    $store->setFields(['tmp/a/direct.md'], ['owner' => 'a']);
    $bridge->ops = [];
    $review->takeDown('bob', 'tmp/a/direct.md', 'off the site');
    $check('takeDown() removes the site\'s copy and deletes the entry', $bridge->ops === [
        ['op' => 'take-down', 'uid' => 'a', 'target' => 'tmp/a/direct.md'],
    ] && $store->entry('tmp/a/direct.md') === [] && !$store->isLive('tmp/a/direct.md'));

    // the conflicts the workflow owes its callers
    $refused = 0;
    try {
        $review->request('a', 'tmp/a/one.md', 'offline', false);
    } catch (ConflictException) {
        $refused++;
    }
    $check('an offline request for a page the site does not serve is a conflict', $refused === 1);
    $trees->freeze('tmp/a/one.md', 50);
    $refused = 0;
    try {
        $review->request('a', 'tmp/a/one.md', 'online', false);
    } catch (ConflictException) {
        $refused++;
    }
    $check('a second request while one waits is a conflict', $refused === 1);
    $trees->drop('tmp/a/one.md');
    $refused = 0;
    try {
        $review->decide('bob', 'tmp/a/one.md', 'approve', '');
    } catch (ConflictException) {
        $refused++;
    }
    $check('a decision with nothing pending is a conflict', $refused === 1);

    echo "26. a deleted source's candidate is withdrawn before a take-off request is recorded\n";
    /*
     * The delete path's discard is best effort, so a candidate can survive the deletion of its source.
     * Writing a `downRequest` next to that candidate would leave two requests on one entry: `decide()`
     * would publish the deleted file's frozen bytes while the take-off request stayed in the queue.
     * The store therefore withdraws the candidate first, in the same write cycle, and records nothing
     * when the bridge will not let it.
     */
    ['store' => $store, 'trees' => $trees, 'articles' => $articles, 'bridge' => $bridge, 'logger' => $logger, 'locking' => $locking] = $makeReview();
    $articles->rows['/tmp/a/gone.md'] = new Article('/tmp/a/gone.md', Article::STATE_EXTERNAL, null, 0, 0, '', 'a');
    $trees->publish('tmp/a/gone.md', 100);
    $trees->freeze('tmp/a/gone.md', 120);
    $store->setFields(['tmp/a/gone.md'], ['owner' => 'a']);
    $requestWhileDiscarding = 'unset';
    $lockWhileDiscarding = null;
    $bridge->onOp = static function (string $op, string $target) use ($trees, $store, $locking, &$requestWhileDiscarding, &$lockWhileDiscarding): void {
        if ($op !== 'discard') {
            return;
        }
        // the bridge runs before the write, so the request must not be in the manifest yet
        $requestWhileDiscarding = $store->entry($target)['downRequest'] ?? null;
        // and the manifest lock must not be held across the bridge call
        $lockWhileDiscarding = in_array('pages/review.json', $locking->held(), true);
        $trees->drop($target);
    };
    $bridge->ops = [];
    $plan = $store->requestTakeDownForDeletedSources(['tmp/a/gone.md'], 777);
    $check('the waiting candidate is discarded through the bridge first', $bridge->ops === [
        ['op' => 'discard', 'uid' => 'a', 'target' => 'tmp/a/gone.md'],
    ], (string)json_encode($bridge->ops));
    $check('and the take-off request is written only after that call', $requestWhileDiscarding === null,
        var_export($requestWhileDiscarding, true));
    $check('the bridge discard runs outside the manifest lock', $lockWhileDiscarding === false,
        var_export($lockWhileDiscarding, true));
    $check('the request is then recorded with sourceDeleted, and nothing failed', $plan === [
        'requested' => ['tmp/a/gone.md'], 'forgotten' => [], 'failed' => [],
    ] && ReviewStore::downRequestOf($store->read(), 'tmp/a/gone.md') === ['at' => 777, 'sourceDeleted' => true],
        (string)json_encode($plan));
    $check('the candidate is gone and the page still waits for the take-off decision',
        !$store->isPending('tmp/a/gone.md') && $store->state('tmp/a/gone.md') === Article::STATE_REQUESTED);

    // the bridge refuses the discard: nothing is recorded, and the caller is told which target failed
    $articles->rows['/tmp/a/stuck.md'] = new Article('/tmp/a/stuck.md', Article::STATE_EXTERNAL, null, 0, 0, '', 'a');
    $trees->publish('tmp/a/stuck.md', 100);
    $trees->freeze('tmp/a/stuck.md', 121);
    $store->setFields(['tmp/a/stuck.md'], ['owner' => 'a']);
    $bridge->ops = [];
    $bridge->fail = new BridgeException('the bridge is down', 'UNREACHABLE');
    $plan = $store->requestTakeDownForDeletedSources(['tmp/a/stuck.md'], 778);
    $check('a discard that fails records no take-off request and reports the target', $plan === [
        'requested' => [], 'forgotten' => [], 'failed' => ['tmp/a/stuck.md'],
    ], (string)json_encode($plan));
    $check('the entry and the candidate are left exactly as they were',
        ReviewStore::downRequestOf($store->read(), 'tmp/a/stuck.md') === null
        && $store->isPending('tmp/a/stuck.md') && $store->isLive('tmp/a/stuck.md'));
    $check('the failure is logged as a warning that names the target',
        count($logger->warnings()) === 1
        && str_contains((string)$logger->warnings()[0], 'could not be withdrawn')
        && ($logger->lines[0]['context']['target'] ?? '') === 'tmp/a/stuck.md',
        (string)json_encode($logger->warnings()));
    $bridge->fail = null;
    $bridge->ops = [];
    $plan = $store->requestTakeDownForDeletedSources(['tmp/a/stuck.md'], 779);
    $check('a retry after the bridge recovers settles the entry',
        $plan === ['requested' => ['tmp/a/stuck.md'], 'forgotten' => [], 'failed' => []]
        && ReviewStore::downRequestOf($store->read(), 'tmp/a/stuck.md') === ['at' => 779, 'sourceDeleted' => true]
        && !$store->isPending('tmp/a/stuck.md'), (string)json_encode($plan));

    echo "27. one decision per article: the pair of requests is a conflict, and the lock excludes the second reviewer\n";
    /*
     * #4: a pending candidate and a take-off request at once cannot be answered by "pending wins" -
     * the approval would publish the version the author asked to take off. The reviewer gets a 409
     * and the explicit way out (withdraw the take-off request).
     *
     * #5: the read of what waits, the bridge call and the manifest write of one article are one
     * critical section. A second decision about the same target fails with 409 instead of racing the
     * first; the per-target key is what the test holds to simulate the other reviewer.
     */
    ['review' => $review, 'store' => $store, 'trees' => $trees, 'articles' => $articles,
        'files' => $files, 'bridge' => $bridge, 'locking' => $locking] = $makeReview();
    $bridge->onOp = static function (string $op, string $target) use ($trees): void {
        if ($op === 'publish') {
            $trees->approve($target, 222);
        } elseif ($op === 'discard') {
            $trees->drop($target);
        } elseif ($op === 'take-down') {
            $trees->takeDown($target);
        }
    };

    $articles->rows['/tmp/a/both.md'] = new Article('/tmp/a/both.md', Article::STATE_EXTERNAL, null, 0, 0, '', 'a');
    $trees->publish('tmp/a/both.md', 100);
    $trees->freeze('tmp/a/both.md', 110);
    $store->setFields(['tmp/a/both.md'], ['owner' => 'a', 'downRequest' => ['at' => 5, 'sourceDeleted' => false]]);
    $bridge->ops = [];
    $refused = '';
    try {
        $review->decide('bob', 'tmp/a/both.md', 'approve', '');
    } catch (ConflictException $e) {
        $refused = $e->getMessage();
    }
    $check('a candidate and a take-off request at once is a conflict, not an approval',
        str_contains($refused, 'Withdraw the take-off request'), $refused);
    $check('nothing was sent to the bridge and neither request was touched', $bridge->ops === []
        && $store->isPending('tmp/a/both.md')
        && ReviewStore::downRequestOf($store->read(), 'tmp/a/both.md') !== null);
    $check('and the decision lock was released after the conflict', $locking->held() === []);

    // the entry points refuse the other direction while one request waits
    $articles->rows['/tmp/a/off.md'] = new Article('/tmp/a/off.md', Article::STATE_EXTERNAL, null, 0, 0, '', 'a');
    $trees->publish('tmp/a/off.md', 100);
    $store->setFields(['tmp/a/off.md'], ['owner' => 'a', 'downRequest' => ['at' => 6, 'sourceDeleted' => false]]);
    $refused = '';
    try {
        $review->request('a', 'tmp/a/off.md', 'online', false);
    } catch (ConflictException $e) {
        $refused = $e->getMessage();
    }
    $check('request(online) refuses while a take-off request waits',
        str_contains($refused, 'take this article off the site'), $refused);
    $articles->rows['/tmp/a/waiting.md'] = new Article('/tmp/a/waiting.md', Article::STATE_INTERNAL, null, 0, 0, '', 'a');
    $trees->freeze('tmp/a/waiting.md', 130);
    $refused = '';
    try {
        $review->request('a', 'tmp/a/waiting.md', 'offline', false);
    } catch (ConflictException $e) {
        $refused = $e->getMessage();
    }
    $check('request(offline) refuses while a publication request waits',
        str_contains($refused, 'request to publish this article is already waiting'), $refused);

    // the per-article lock: hold the key the service names, then ask for the same decision
    $articles->rows['/tmp/a/locked.md'] = new Article('/tmp/a/locked.md', Article::STATE_INTERNAL, null, 0, 0, '', 'a');
    $files->authorFiles['a:Pages/locked.md'] = "# locked\n";
    $trees->freeze('tmp/a/locked.md', 140);
    $lockKey = 'pages-review-tmp/a/locked.md';
    $locking->acquireLock($lockKey, \OCP\Lock\ILockingProvider::LOCK_EXCLUSIVE);
    $bridge->ops = [];
    $refused = '';
    try {
        $review->decide('bob', 'tmp/a/locked.md', 'approve', '');
    } catch (ConflictException $e) {
        $refused = $e->getMessage();
    }
    $check('a decision while another one holds the article is a conflict',
        str_contains($refused, 'Another decision about this article'), $refused);
    $check('and the bridge is not called at all', $bridge->ops === []);
    $check('and the other decision\'s lock is left alone', $locking->held() === [$lockKey]);
    $locking->releaseLock($lockKey, \OCP\Lock\ILockingProvider::LOCK_EXCLUSIVE);
    $bridge->ops = [];
    $review->decide('bob', 'tmp/a/locked.md', 'approve', '');
    $check('the next decision runs, publishes, and releases the key it took',
        $store->isLive('tmp/a/locked.md') && in_array($lockKey, $locking->acquired, true)
        && $locking->held() === []);

    $locking->acquireLock($lockKey, \OCP\Lock\ILockingProvider::LOCK_EXCLUSIVE);
    $bridge->ops = [];
    $refused = '';
    try {
        $review->takeDown('bob', 'tmp/a/locked.md', '');
    } catch (ConflictException $e) {
        $refused = $e->getMessage();
    }
    $check('a take-down while a decision holds the article is the same conflict',
        str_contains($refused, 'Another decision about this article'), $refused);
    $check('and it does not reach the bridge either', $bridge->ops === []);
    $locking->releaseLock($lockKey, \OCP\Lock\ILockingProvider::LOCK_EXCLUSIVE);
    $bridge->ops = [];
    $review->takeDown('bob', 'tmp/a/locked.md', '');
    $check('afterwards the take-down goes through and the article stays forgotten',
        $bridge->ops === [['op' => 'take-down', 'uid' => 'a', 'target' => 'tmp/a/locked.md']]
        && !$store->isLive('tmp/a/locked.md') && $locking->held() === []);

    // #3: filing a request is the same critical section as deciding one
    $articles->rows['/tmp/a/req.md'] = new Article('/tmp/a/req.md', Article::STATE_INTERNAL, null, 0, 0, '', 'a');
    $files->authorFiles['a:Pages/req.md'] = "# req\n";
    $reqLock = 'pages-review-tmp/a/req.md';
    $locking->acquireLock($reqLock, \OCP\Lock\ILockingProvider::LOCK_EXCLUSIVE);
    $bridge->ops = [];
    $refused = '';
    try {
        $review->request('a', 'tmp/a/req.md', 'online', false);
    } catch (ConflictException $e) {
        $refused = $e->getMessage();
    }
    $check('request() refuses while another decision holds the article',
        str_contains($refused, 'Another decision about this article') && $bridge->ops === [], $refused);
    $check('and it leaves the other decision\'s lock alone', $locking->held() === [$reqLock]);
    $locking->releaseLock($reqLock, \OCP\Lock\ILockingProvider::LOCK_EXCLUSIVE);

    $heldDuringCandidate = null;
    $bridge->onOp = static function (string $op, string $target) use ($trees, $locking, &$heldDuringCandidate): void {
        if ($op === 'candidate') {
            // the file the bridge freezes is the one a decision must not see as "not pending" yet
            $heldDuringCandidate = in_array('pages-review-tmp/a/req.md', $locking->held(), true);
            $trees->freeze($target, 111);
        }
    };
    $review->request('a', 'tmp/a/req.md', 'online', false);
    $check('request() holds the per-article lock across the bridge call and the write',
        $heldDuringCandidate === true && $locking->held() === [] && $store->isPending('tmp/a/req.md'),
        var_export($heldDuringCandidate, true));

    echo "28. the approved bytes carry a digest from the request to the approval\n";
    /*
     * #6: the bridge answers the sha256 of the frozen candidate, and request(online) records it.
     * #7: the approval sends exactly the digest the entry holds, so the bridge can refuse a candidate
     *     the author replaced after the request. #8: withdrawing or discarding the candidate clears
     *     the digest. A bridge's DIGEST_MISMATCH is a 409 the reviewer can act on, with the candidate
     *     deliberately left in pending/ (the author may still be waiting on it).
     */
    ['review' => $review, 'store' => $store, 'appData' => $appData, 'trees' => $trees, 'articles' => $articles,
        'files' => $files, 'bridge' => $bridge, 'locking' => $locking] = $makeReview();
    $bridge->onOp = static function (string $op, string $target) use ($trees): void {
        if ($op === 'candidate') {
            $trees->freeze($target, 111);
        } elseif ($op === 'publish') {
            $trees->approve($target, 222);
        } elseif ($op === 'discard') {
            $trees->drop($target);
        } elseif ($op === 'take-down') {
            $trees->takeDown($target);
        }
    };
    $articles->rows['/tmp/a/one.md'] = new Article('/tmp/a/one.md', Article::STATE_INTERNAL, null, 0, 0, '', 'a');
    $files->authorFiles['a:Pages/one.md'] = "# one\n";
    $answer = $review->request('a', 'tmp/a/one.md', 'online', false);
    $check('request(online) records the digest the bridge answered',
        ($store->entry('tmp/a/one.md')['candidateDigest'] ?? '') === $bridge->candidateDigest
        && ($answer['candidateDigest'] ?? '') === $bridge->candidateDigest,
        (string)$appData->content);
    $check('the owner is recorded next to it', ($store->entry('tmp/a/one.md')['owner'] ?? '') === 'a');

    $entryDigest = (string)($store->entry('tmp/a/one.md')['candidateDigest'] ?? '');
    $bridge->ops = [];
    $review->decide('bob', 'tmp/a/one.md', 'approve', '');
    $check('decide(approve) hands the entry\'s digest to the bridge',
        count($bridge->ops) === 1 && ($bridge->ops[0]['digest'] ?? '') === $entryDigest
        && $bridge->ops[0]['op'] === 'publish',
        (string)json_encode($bridge->ops));

    // #8: withdrawing the request clears the binding
    $articles->rows['/tmp/a/two.md'] = new Article('/tmp/a/two.md', Article::STATE_INTERNAL, null, 0, 0, '', 'a');
    $review->request('a', 'tmp/a/two.md', 'online', false);
    $check('a second request binds its own candidate',
        ($store->entry('tmp/a/two.md')['candidateDigest'] ?? '') === $bridge->candidateDigest);
    $review->cancel('a', 'tmp/a/two.md', false);
    $check('cancel clears the candidate digest',
        ($store->entry('tmp/a/two.md')['candidateDigest'] ?? 'x') === '' && !$store->isPending('tmp/a/two.md'),
        (string)$appData->content);

    // #8: a rejection discards the candidate and clears the binding too
    $articles->rows['/tmp/a/three.md'] = new Article('/tmp/a/three.md', Article::STATE_INTERNAL, null, 0, 0, '', 'a');
    $review->request('a', 'tmp/a/three.md', 'online', false);
    $review->decide('bob', 'tmp/a/three.md', 'reject', 'no');
    $check('discard clears the candidate digest as well',
        ($store->entry('tmp/a/three.md')['candidateDigest'] ?? 'x') === '');

    // the bridge refuses the bytes: a 409 naming both digests, and the candidate is left pending
    $articles->rows['/tmp/a/four.md'] = new Article('/tmp/a/four.md', Article::STATE_INTERNAL, null, 0, 0, '', 'a');
    $files->authorFiles['a:Pages/four.md'] = "# four\n";
    $review->request('a', 'tmp/a/four.md', 'online', false);
    $bridge->fail = new BridgeException(
        'the candidate changed after it was requested',
        'DIGEST_MISMATCH',
        ['expected' => $bridge->candidateDigest, 'actual' => '0000'],
        400,
    );
    $refused = null;
    try {
        $review->decide('bob', 'tmp/a/four.md', 'approve', '');
    } catch (ConflictException $e) {
        $refused = $e;
    }
    $check('a DIGEST_MISMATCH is a 409 conflict that says to request the page again',
        $refused instanceof ConflictException
        && str_contains($refused->getMessage(), 'ask the author to request the page again'),
        $refused === null ? 'no exception' : $refused->getMessage());
    $check('and it carries both digests',
        ($refused?->getDetails()['expected'] ?? '') === $bridge->candidateDigest
        && ($refused?->getDetails()['actual'] ?? '') === '0000');
    $check('🔒 the candidate is left pending for the author, and the digest is cleared',
        $store->isPending('tmp/a/four.md')
        && ($store->entry('tmp/a/four.md')['candidateDigest'] ?? 'x') === '');
    $check('and the decision lock was released', $locking->held() === []);
    $bridge->fail = null;

    echo "29. the two content directories come from the bridge, not from the filecache\n";
    /*
     * The bridge writes online/ and pending/ itself - a temporary file and a rename, never the
     * Nextcloud Files API - so a folder listing goes through a filecache that only learns about a
     * file when something scans the folder. With the default filesystem_check_changes=0 that never
     * happens: a page that was just published reads as unpublished, a candidate that was just frozen
     * as "nothing waits", and decide()/takeDown() then answer "there is nothing here". SiteTrees
     * therefore asks the bridge for both maps in **one** request (GET /site/paths) and keeps the
     * Files API scan only for a bridge that cannot answer.
     */
    $files = new FileService();
    $logger = new FakeLogger();
    $bridge = new BridgeClient();
    $trees = new SiteTrees(new FakeSiteRegistry(), $files, $bridge, $logger);
    $bridge->paths = [
        'online' => ['tmp/a/one.md' => 111, 'tmp/b/two.md' => 222],
        'pending' => ['tmp/c/three.md' => 333],
    ];
    $check('isLive()/isPending() answer from the bridge\'s snapshot',
        $trees->isOnline('tmp/a/one.md') && !$trees->isPending('tmp/a/one.md') && $trees->isPending('tmp/c/three.md'));
    $check('the mtime maps are the stamps',
        $trees->onlineStamp('tmp/b/two.md') === 222 && $trees->pendingStamp('tmp/c/three.md') === 333
        && $trees->onlineStamp('tmp/none.md') === 0);
    $check('counts()/targets come from the same read',
        $trees->counts() === ['pending' => 1, 'online' => 2]
        && $trees->onlineTargets() === ['tmp/a/one.md', 'tmp/b/two.md']
        && $trees->pendingTargets() === ['tmp/c/three.md'],
        (string)json_encode($trees->counts()));
    $check('one bridge request answers every question of the request', $bridge->snapshots === 1, (string)$bridge->snapshots);
    $check('a bridge answer marks the snapshot as trusted', $trees->isTrusted() === true);
    $check('the Files API was not asked at all', $files->folderRequests === [], (string)json_encode($files->folderRequests));
    $trees->forget();
    $trees->counts();
    $check('forget() drops the cache, so the next read asks the bridge again', $bridge->snapshots === 2, (string)$bridge->snapshots);

    // the bridge is down: the Files API scan takes over, and the answer is the empty set, never an error
    $bridge->pathsFail = new BridgeException('the bridge is down', 'UNREACHABLE');
    $trees->forget();
    $check('an unreachable bridge reads as the empty set and does not throw',
        $trees->counts() === ['pending' => 0, 'online' => 0] && $trees->isOnline('tmp/a/one.md') === false);
    $check('the fallback asked the Files API for both directories',
        $files->folderRequests === ['siteuser:/Sites/site/online', 'siteuser:/Sites/site/pending'],
        (string)json_encode($files->folderRequests));
    $check('and the failure is logged as a warning', count($logger->warnings()) === 1, (string)json_encode($logger->warnings()));
    $check('the fallback answer is reported as untrusted', $trees->isTrusted() === false);

    // the fallback really walks the folder: the same target keys, with each file's own mtime
    $files->folders['siteuser:/Sites/site/online'] = new \Probe\FakeSiteFolder('online', [
        new \OCP\Files\File('one.md', 111),
        new \Probe\FakeSiteFolder('b', [new \OCP\Files\File('two.md', 222)]),
    ]);
    $trees->forget();
    $check('the fallback scan reads the folder tree when the bridge is down',
        $trees->onlineTargets() === ['b/two.md', 'one.md'] && $trees->onlineStamp('b/two.md') === 222
        && $trees->counts() === ['pending' => 0, 'online' => 2],
        (string)json_encode($trees->online()));

    // a site that is not configured asks nothing at all: no bridge call, no Files API walk
    $bridge->pathsFail = null;
    $bridge->snapshots = 0;
    $files->folderRequests = [];
    $noSite = new SiteTrees(new FakeSiteRegistry(null), $files, $bridge, $logger);
    $check('no configured site reads as the empty set and asks nothing',
        $noSite->counts() === ['pending' => 0, 'online' => 0] && $bridge->snapshots === 0
        && $files->folderRequests === []
        && $noSite->isTrusted() === true);

    echo "30. the author delta queue: dedupe, cap, settle and a bounded replay\n";
    /*
     * A save whose delta the bridge did not take used to be lost until the next boot's one-shot
     * overwrite. It is now durable: the failed add/update/remove is appended to appconfig and
     * replayed at the next boot or by `occ pages:delta-flush`. The queue is bounded (500 entries,
     * oldest dropped) and deduplicated by target, because the last event of a file is the one that
     * matters; the replay is bounded by count, per-entry timeout and wall-clock budget so a dead
     * bridge cannot hold a request open once per entry.
     */
    $queueConfig = new FakeConfig();
    $queueLogger = new FakeLogger();
    $queue = new DeltaQueue($queueConfig, $queueLogger, new FakeLocking());
    $check('a fresh queue is empty', $queue->count() === 0 && $queue->all() === []);
    $check('a non-queueable op (candidate) is refused',
        $queue->enqueue('candidate', 'a', 'tmp/a/one.md', 1) === false && $queue->count() === 0);
    $check('an entry without a target (a folder mirror) is refused',
        $queue->enqueue('mirror', 'a', '  ', 1) === false && $queue->count() === 0);
    $check('a failed add is queued with the documented shape',
        $queue->enqueue('add', 'a', '/tmp/a/one.md', 100) === true
        && $queue->all() === [['op' => 'add', 'uid' => 'a', 'target' => 'tmp/a/one.md', 'at' => 100, 'attempts' => 0]],
        (string)json_encode($queue->all()));
    $check('a newer event for the same target replaces the old one',
        $queue->enqueue('update', 'a', 'tmp/a/one.md', 200) === true
        && $queue->all() === [['op' => 'update', 'uid' => 'a', 'target' => 'tmp/a/one.md', 'at' => 200, 'attempts' => 0]],
        (string)json_encode($queue->all()));
    $queue->enqueue('remove', 'b', 'tmp/b/two.md', 300);
    $check('settle() removes every entry of the target and keeps the rest',
        $queue->settle('tmp/a/one.md') === true
        && $queue->all() === [['op' => 'remove', 'uid' => 'b', 'target' => 'tmp/b/two.md', 'at' => 300, 'attempts' => 0]],
        (string)json_encode($queue->all()));
    $check('settle() of a target that is not queued writes nothing', $queue->settle('tmp/none.md') === false);
    $check('the queue is one appconfig value, and it is deleted once empty',
        $queue->settle('tmp/b/two.md') === true
        && $queue->count() === 0
        && $queueConfig->getAppValue('pages', DeltaQueue::KEY, '') === '');

    // the cap: 510 failed saves leave the newest 500, and the drop is said out loud
    $capConfig = new FakeConfig();
    $capLogger = new FakeLogger();
    $capQueue = new DeltaQueue($capConfig, $capLogger, new FakeLocking());
    for ($i = 0; $i < DeltaQueue::MAX_ENTRIES + 10; $i++) {
        $capQueue->enqueue('add', 'a', 'tmp/a/file-' . $i . '.md', $i);
    }
    $capped = $capQueue->all();
    $check('the queue is capped at 500 entries', count($capped) === DeltaQueue::MAX_ENTRIES, (string)count($capped));
    $check('the oldest entries are the ones dropped',
        ($capped[0]['target'] ?? '') === 'tmp/a/file-10.md'
        && ($capped[DeltaQueue::MAX_ENTRIES - 1]['target'] ?? '') === 'tmp/a/file-509.md',
        (string)($capped[0]['target'] ?? '?'));
    $check('and the drop is logged as a warning naming the limit',
        count($capLogger->warnings()) === 10
        && str_contains((string)$capLogger->warnings()[0], 'queue is full')
        && ($capLogger->lines[0]['context']['dropped'] ?? 0) === 1,
        (string)json_encode($capLogger->warnings()));

    // the replay: successes leave the queue, failures stay, a non-retryable refusal does not stop it
    $replayConfig = new FakeConfig();
    $replayLogger = new FakeLogger();
    $replayQueue = new DeltaQueue($replayConfig, $replayLogger, new FakeLocking());
    $replayQueue->enqueue('add', 'a', 'tmp/a/one.md', 1);
    $replayQueue->enqueue('remove', 'b', 'tmp/b/two.md', 2);
    $replayQueue->enqueue('add', 'c', 'tmp/c/three.md', 3);
    $sent = [];
    $result = $replayQueue->replay(
        static function (array $body, int $timeout) use (&$sent): void {
            $sent[] = [$body, $timeout];
            if ($body['target'] === 'tmp/b/two.md') {
                throw new BridgeException('the bridge refused this delta', 'BAD_RESPONSE');
            }
        },
        50,
        7,
        60,
    );
    $check('a replay sends in order, removes what went through and keeps the failure',
        $result === ['sent' => 2, 'failed' => 1, 'remaining' => 1, 'stopped' => 'done'],
        (string)json_encode($result));
    $check('the per-entry timeout is handed to the sender',
        ($sent[0][1] ?? 0) === 7 && ($sent[0][0]['target'] ?? '') === 'tmp/a/one.md'
        && ($sent[0][0]['op'] ?? '') === 'add',
        (string)json_encode($sent));
    $check('the failed target is the one still queued',
        $replayQueue->all() === [['op' => 'remove', 'uid' => 'b', 'target' => 'tmp/b/two.md', 'at' => 2, 'attempts' => 1]],
        (string)json_encode($replayQueue->all()));

    // an unreachable bridge ends the round at once instead of waiting out every entry's timeout
    $replayQueue->clear();
    $replayQueue->enqueue('add', 'a', 'tmp/a/one.md', 1);
    $replayQueue->enqueue('add', 'b', 'tmp/b/two.md', 2);
    $replayQueue->enqueue('add', 'c', 'tmp/c/three.md', 3);
    $attempts = 0;
    $result = $replayQueue->replay(
        static function () use (&$attempts): void {
            $attempts++;
            throw new BridgeException('the bridge is down', 'UNREACHABLE');
        },
        50,
        30,
        60,
    );
    $check('an unreachable bridge stops the round at the first entry',
        $attempts === 1 && $result === ['sent' => 0, 'failed' => 1, 'remaining' => 3, 'stopped' => 'unreachable'],
        (string)json_encode($result));

    // the count bound: two entries per round, the rest stays for the next one
    $attempts = 0;
    $result = $replayQueue->replay(
        static function () use (&$attempts): void {
            $attempts++;
        },
        2,
        30,
        60,
    );
    $check('the replay stops at its entry limit and leaves the rest queued',
        $attempts === 2 && $result === ['sent' => 2, 'failed' => 0, 'remaining' => 1, 'stopped' => 'limit'],
        (string)json_encode($result));

    // concurrency: every write takes the queue lock, and a transient holder is retried, not dropped
    $lockConfig = new QueueConfig();
    $queueLock = new FakeLocking();
    $lockConfig->locking = $queueLock;
    $lockQueue = new DeltaQueue($lockConfig, new FakeLogger(), $queueLock);
    $queueLock->failAcquires = 1; // another request held the queue for a moment
    $check('a transient lock holder is retried instead of losing the delta',
        $lockQueue->enqueue('add', 'a', 'tmp/a/one.md', 1) === true
        && $lockQueue->enqueue('update', 'b', 'tmp/b/two.md', 2) === true
        && array_column($lockQueue->all(), 'target') === ['tmp/a/one.md', 'tmp/b/two.md']
        && $lockConfig->queueWritesLocked === [true, true]
        && $queueLock->held() === [],
        (string)json_encode([$lockQueue->all(), $lockConfig->queueWritesLocked]));

    $heldConfig = new QueueConfig();
    $heldLock = new FakeLocking();
    $heldConfig->locking = $heldLock;
    $heldQueue = new DeltaQueue($heldConfig, new FakeLogger(), $heldLock);
    $heldLock->acquireLock(DeltaQueue::LOCK_KEY, \OCP\Lock\ILockingProvider::LOCK_EXCLUSIVE);
    $queuedWhileHeld = $heldQueue->enqueue('add', 'a', 'tmp/a/three.md', 3);
    $heldLock->releaseLock(DeltaQueue::LOCK_KEY, \OCP\Lock\ILockingProvider::LOCK_EXCLUSIVE);
    $check('a queue lock held through every retry is reported, not written around',
        $queuedWhileHeld === false && $heldQueue->count() === 0,
        var_export($queuedWhileHeld, true));

    // a newer failure queued while a replay's send is in flight is a different attempt: the success
    // of the older one must not settle the newer one away
    $raceConfig = new FakeConfig();
    $raceQueue = new DeltaQueue($raceConfig, new FakeLogger(), new FakeLocking());
    $raceQueue->enqueue('add', 'a', 'tmp/a/race.md', 10);
    $raceQueue->replay(static function (array $body) use ($raceQueue): void {
        // another request's save failed for the same file while this send was in flight
        $raceQueue->enqueue('remove', 'a', 'tmp/a/race.md', 11);
    }, 50, 7, 60);
    $check('a delta queued during the send survives the older attempt\'s settlement',
        $raceQueue->all() === [['op' => 'remove', 'uid' => 'a', 'target' => 'tmp/a/race.md', 'at' => 11, 'attempts' => 0]],
        (string)json_encode($raceQueue->all()));

    // the encode failure keeps the stored value instead of replacing the whole queue with []
    $badConfig = new FakeConfig();
    $badLogger = new FakeLogger();
    $badQueue = new DeltaQueue($badConfig, $badLogger, new FakeLocking());
    $badQueue->enqueue('add', 'a', 'tmp/a/good.md', 1);
    $rawBefore = $badConfig->getAppValue('pages', DeltaQueue::KEY, '');
    $encoded = $badQueue->enqueue('add', "a\xB1", 'tmp/a/bad.md', 2);
    $check('an entry that cannot be encoded leaves the stored queue untouched',
        $encoded === false
        && $badConfig->getAppValue('pages', DeltaQueue::KEY, '') === $rawBefore
        && array_column($badQueue->all(), 'target') === ['tmp/a/good.md'],
        (string)$badConfig->getAppValue('pages', DeltaQueue::KEY, ''));
    $check('and the failure is logged as a warning that says so',
        count($badLogger->warnings()) === 1
        && str_contains((string)$badLogger->warnings()[0], 'could not be encoded'),
        (string)json_encode($badLogger->warnings()));

    // poison: a non-retryable refusal is counted, and the entry goes after MAX_ATTEMPTS
    $poisonConfig = new FakeConfig();
    $poisonLogger = new FakeLogger();
    $poisonQueue = new DeltaQueue($poisonConfig, $poisonLogger, new FakeLocking());
    $poisonQueue->enqueue('add', 'a', 'tmp/a/poison.md', 1);
    $refuse = static function (): void {
        throw new BridgeException('the bridge refused this delta', 'BAD_RESPONSE');
    };
    for ($round = 1; $round < DeltaQueue::MAX_ATTEMPTS; $round++) {
        $poisonQueue->replay($refuse, 50, 7, 60);
    }
    $check('a non-retryable failure is counted on the entry',
        $poisonQueue->count() === 1 && ($poisonQueue->all()[0]['attempts'] ?? 0) === DeltaQueue::MAX_ATTEMPTS - 1,
        (string)json_encode($poisonQueue->all()));
    $poisonQueue->replay($refuse, 50, 7, 60);
    $poisonWarnings = $poisonLogger->warnings();
    $check('a poison entry is dropped once it reaches the attempt limit',
        $poisonQueue->count() === 0
        && count($poisonWarnings) === DeltaQueue::MAX_ATTEMPTS + 1
        && str_contains((string)$poisonWarnings[count($poisonWarnings) - 1], 'dropped'),
        (string)json_encode($poisonWarnings));

    // retryable: an unreachable bridge keeps the entry and never counts towards the poison limit
    $retryConfig = new FakeConfig();
    $retryLogger = new FakeLogger();
    $retryQueue = new DeltaQueue($retryConfig, $retryLogger, new FakeLocking());
    $retryQueue->enqueue('add', 'a', 'tmp/a/retry.md', 1);
    for ($round = 0; $round < 10; $round++) {
        $retryQueue->replay(static function (): void {
            throw new BridgeException('the bridge is down', 'UNREACHABLE');
        }, 50, 7, 60);
    }
    $check('a retryable failure keeps the entry and does not count as poison',
        $retryQueue->count() === 1 && ($retryQueue->all()[0]['attempts'] ?? -1) === 0
        && str_contains((string)$retryLogger->warnings()[0], 'could not be delivered'),
        (string)json_encode($retryQueue->all()));

    // the send path: a failure queues, a later success settles the same target
    $deltaConfig = new FakeConfig();
    $deltaConfig->setAppValue('pages', 'site', (string)json_encode(['nc_user' => 'siteuser', 'nc_path' => '/Sites/site']));
    $deltaLogger = new FakeLogger();
    $deltaQueue = new DeltaQueue($deltaConfig, $deltaLogger, new FakeLocking());
    $deltaRegistry = new SiteRegistry($deltaConfig, new FakeCrypto(), new L10N(), new FakeUserManager());
    $deltaBridge = new BridgeClient();
    $deltaStore = $build()['store'];
    $delta = new AuthorDeltaService(
        $deltaRegistry,
        $deltaBridge,
        new \OCA\Pages\Service\AuthorSourcesService(),
        $deltaConfig,
        $deltaLogger,
        $deltaStore,
        $deltaQueue,
    );
    $deltaBridge->deltaFail = new BridgeException('the bridge is restarting', 'UNREACHABLE');
    $delta->written(new \OCP\Files\Node('/alice/files/Pages/one.md'));
    $queuedSave = $deltaQueue->all();
    $check('a save whose delta failed is queued for the same target the bridge would have received',
        count($queuedSave) === 1
        && $queuedSave[0]['op'] === 'add'
        && $queuedSave[0]['uid'] === 'alice'
        && $queuedSave[0]['target'] === 'tmp/alice/one.md'
        && $queuedSave[0]['at'] > 0,
        (string)json_encode($queuedSave));
    $check('the warning says whether the event was queued',
        count($deltaLogger->warnings()) === 1
        && ($deltaLogger->lines[0]['context']['queued'] ?? null) === true,
        (string)json_encode($deltaLogger->warnings()));
    $deltaQueue->enqueue('remove', 'bob', 'tmp/bob/two.md', 5);
    $deltaBridge->deltaFail = null;
    $delta->written(new \OCP\Files\Node('/alice/files/Pages/one.md'));
    $check('a delta that goes through clears the old entry of its target only',
        $deltaQueue->all() === [['op' => 'remove', 'uid' => 'bob', 'target' => 'tmp/bob/two.md', 'at' => 5, 'attempts' => 0]],
        (string)json_encode($deltaQueue->all()));

    // the one-shot overwrite carries the complete state, so it supersedes anything still queued
    $deltaConfig->setAppValue('pages', 'bridge_shared_secret', 'secret');
    $deltaQueue->enqueue('remove', 'carol', 'tmp/carol/three.md', 9);
    $deltaBridge->authorDeltas = [];
    $check('a successful one-shot overwrite clears the queue it supersedes',
        $delta->overwrite() === true
        && $deltaQueue->count() === 0
        && ($deltaBridge->authorDeltas[0]['op'] ?? '') === 'overwrite',
        (string)json_encode($deltaBridge->authorDeltas));

    echo "31. Apply source carries the force flag through to the bridge\n";
    /*
     * The delete guard is a question: the first call must not skip it, and the confirmed second call
     * must. The bridge answers HTTP 200 with `ok: false` and the guard block, so the controller has
     * to keep that block - the dialog reads `removed`/`tree`/`old`/`new` from it - and it must not
     * invent a force offer for SOURCE_EMPTY, which `force` cannot fix.
     */
    $forceBridge = new BridgeClient();
    $forceRequest = new FakeRequest();
    $forceController = new BuildController(
        $forceRequest,
        new FakeUserSession(),
        new FakeGroupManager(),
        new FakeSiteRegistry(),
        new FileService(),
        $forceBridge,
        new L10N(),
        new \OCA\Pages\Service\AuthorSourcesService(),
    );

    $forceRequest->params = [];
    $data = $forceController->build()->getData();
    $check('a first Apply source sends force:false',
        ($forceBridge->mirrors[0]['force'] ?? null) === false && ($data['rebuilt'] ?? false) === true,
        (string)json_encode($forceBridge->mirrors));
    $forceRequest->params = ['force' => true];
    $forceController->build();
    $check('a confirmed Apply source sends force:true', ($forceBridge->mirrors[1]['force'] ?? null) === true);
    $forceRequest->params = ['force' => 'true'];
    $forceController->build();
    $check('the string "true" from a form body is accepted as force', ($forceBridge->mirrors[2]['force'] ?? null) === true);
    $forceRequest->params = ['force' => '0'];
    $forceController->build();
    $check('anything else is force:false', ($forceBridge->mirrors[3]['force'] ?? null) === false);

    $forceBridge->mirrorAnswer = [
        'ok' => false,
        'op' => 'mirror',
        'rebuilt' => false,
        'error' => 'MIRROR_DELETE_GUARD',
        'tree' => 'preview',
        'old' => 40,
        'new' => 12,
        'removed' => 28,
        'guard' => ['tree' => 'preview', 'ratio' => 0.5, 'min' => 3],
        'message' => 'the assembly would remove 28 of the 40 files',
    ];
    $forceRequest->params = [];
    $data = $forceController->build()->getData();
    $check('a refused round keeps ok:false and the guard block the dialog reads',
        ($data['ok'] ?? null) === false
        && ($data['error'] ?? '') === 'MIRROR_DELETE_GUARD'
        && ($data['tree'] ?? '') === 'preview'
        && ($data['old'] ?? 0) === 40
        && ($data['new'] ?? 0) === 12
        && ($data['removed'] ?? 0) === 28
        && ($data['guard']['ratio'] ?? 0) === 0.5
        && ($data['guard']['min'] ?? 0) === 3,
        (string)json_encode($data));
    $check('and it still reports the failed round as not rebuilt',
        ($data['rebuilt'] ?? true) === false
        && array_key_exists('build', $data) && $data['build'] === null);

    $forceBridge->mirrorAnswer = [
        'ok' => false,
        'op' => 'mirror',
        'rebuilt' => false,
        'error' => 'SOURCE_EMPTY',
        'message' => 'the source folder is empty',
    ];
    $data = $forceController->build()->getData();
    $check('SOURCE_EMPTY keeps its error and is not turned into a force offer',
        ($data['ok'] ?? null) === false
        && ($data['error'] ?? '') === 'SOURCE_EMPTY'
        && ($data['message'] ?? '') === 'the source folder is empty'
        && !array_key_exists('force', $data),
        (string)json_encode($data));

    echo "32. the recoverable edges: a candidate without an entry, a gone source, and a rename's old address\n";
    /*
     * The states the folders can hold while the manifest has not caught up, and the ones a decision
     * has to read from the filesystem instead of from a stored flag:
     *
     *   X1  `candidate` writes `pending/` before the request writes its entry, so a crash between the
     *       two leaves a candidate with no entry (and no article row). `cancel()` must still discard
     *       it, and `decide()` must derive the owner from the path and give the decision a landing
     *       point before it calls the bridge.
     *   R1  a target that is not served but has a candidate is not "forget it": a discard that fails
     *       leaves the candidate and the entry, and the target is reported in `failed`.
     *   X2  the delete bookkeeping reads the union of entries, `pending/` and `online/`, so a page the
     *       site serves without an entry is still asked to be taken down.
     *   C4  only a target that really has an entry counts as forgotten: a target that only `pending/`
     *       knew is discarded (and its cache row dropped), but it is not an entry the delete removed.
     *   X2/X6 an approval of a candidate whose source is gone is refused (409 SOURCE_GONE), and a
     *       `sourceDeleted: true` flag whose file has come back does not turn a refusal into a
     *       handover to the orphan owner - the file is read at decision time.
     *   X6  a rename is a delete of the old address: the old target gets the take-off request, the
     *       new one stays a draft.
     */
    ['review' => $review, 'store' => $store, 'trees' => $trees, 'articles' => $articles,
        'files' => $files, 'bridge' => $bridge, 'orphan' => $orphan] = $makeReview();
    $bridge->onOp = static function (string $op, string $target) use ($trees): void {
        if ($op === 'candidate') {
            $trees->freeze($target, 111);
        } elseif ($op === 'publish') {
            $trees->approve($target, 222);
        } elseif ($op === 'discard') {
            $trees->drop($target);
        } elseif ($op === 'take-down') {
            $trees->takeDown($target);
        }
    };

    // ① X1: cancel() of a candidate the manifest does not know
    $trees->freeze('tmp/a/orphaned.md', 100);
    $bridge->ops = [];
    $answer = $review->cancel('a', 'tmp/a/orphaned.md', false);
    $check('X1: cancel() withdraws a candidate that has neither an entry nor an article row',
        $bridge->ops === [['op' => 'discard', 'uid' => 'a', 'target' => 'tmp/a/orphaned.md']]
        && !$store->isPending('tmp/a/orphaned.md')
        && ($answer['cancelled'] ?? false) === true,
        (string)json_encode($bridge->ops));

    // ① X1: decide() of the same shape derives the owner and writes the entry before the bridge call
    $trees->freeze('tmp/a/orphaned2.md', 100);
    $files->authorFiles['a:Pages/orphaned2.md'] = "# orphaned2\n";
    $ownerWhilePublishing = null;
    $bridge->onOp = static function (string $op, string $target) use ($trees, $store, &$ownerWhilePublishing): void {
        if ($op === 'publish') {
            // the entry must already be there when the bridge is asked to publish
            $ownerWhilePublishing = (string)($store->entry($target)['owner'] ?? '');
            $trees->approve($target, 222);
        } elseif ($op === 'discard') {
            $trees->drop($target);
        } elseif ($op === 'take-down') {
            $trees->takeDown($target);
        }
    };
    $bridge->ops = [];
    $review->decide('bob', 'tmp/a/orphaned2.md', 'approve', '');
    $check('X1: decide() derives the owner of a candidate without an entry and records it before the bridge call',
        $ownerWhilePublishing === 'a'
        && ($store->entry('tmp/a/orphaned2.md')['owner'] ?? '') === 'a'
        && $store->isLive('tmp/a/orphaned2.md'),
        var_export($ownerWhilePublishing, true));

    // ① X1: the same landing point carries a refusal's trail
    $trees->freeze('tmp/a/refused.md', 150);
    $bridge->ops = [];
    $review->decide('bob', 'tmp/a/refused.md', 'reject', 'not yet');
    $refusedEntry = $store->entry('tmp/a/refused.md');
    $check('X1: a rejected candidate without an entry records the reviewer and the note',
        ($refusedEntry['decidedBy'] ?? '') === 'bob'
        && ($refusedEntry['note'] ?? '') === 'not yet'
        && ($refusedEntry['owner'] ?? '') === 'a'
        && !$store->isPending('tmp/a/refused.md'),
        (string)json_encode($refusedEntry));

    // ② R1: a non-online target whose candidate cannot be withdrawn is not forgotten
    $articles->rows['/tmp/a/stuck2.md'] = new Article('/tmp/a/stuck2.md', Article::STATE_INTERNAL, null, 0, 0, '', 'a');
    $store->setFields(['tmp/a/stuck2.md'], ['owner' => 'a', 'note' => 'keep me', 'decidedBy' => 'bob']);
    $trees->freeze('tmp/a/stuck2.md', 120);
    $bridge->fail = new BridgeException('the bridge is down', 'UNREACHABLE');
    $plan = $store->requestTakeDownForDeletedSources(['tmp/a/stuck2.md'], 900);
    $check('R1: a non-online target with a stuck candidate keeps its entry and is reported in failed',
        $plan === ['requested' => [], 'forgotten' => [], 'failed' => ['tmp/a/stuck2.md']]
        && ($store->entry('tmp/a/stuck2.md')['note'] ?? '') === 'keep me'
        && ReviewStore::downRequestOf($store->read(), 'tmp/a/stuck2.md') === null
        && $store->isPending('tmp/a/stuck2.md')
        && isset($articles->rows['/tmp/a/stuck2.md']),
        (string)json_encode($plan));
    $bridge->fail = null;

    // ③ X2/X6: approving a candidate whose source file is gone
    $trees->freeze('tmp/a/gonecand.md', 130);
    $bridge->ops = [];
    $refused = null;
    try {
        $review->decide('bob', 'tmp/a/gonecand.md', 'approve', '');
    } catch (ConflictException $e) {
        $refused = $e;
    }
    $check('X2: approving a candidate whose source file is gone is a 409 SOURCE_GONE and publishes nothing',
        $refused instanceof ConflictException
        && ($refused->getDetails()['reason'] ?? '') === 'SOURCE_GONE'
        && $bridge->ops === []
        && $store->isPending('tmp/a/gonecand.md'),
        $refused === null ? 'no exception' : $refused->getMessage());

    // ④ X6: sourceDeleted says true, but the file is back - a refusal keeps the page and clears the request
    $trees->publish('tmp/a/back.md', 100);
    $files->authorFiles['a:Pages/back.md'] = "# back\n";
    $store->setFields(['tmp/a/back.md'], ['owner' => 'a', 'downRequest' => ['at' => 5, 'sourceDeleted' => true]]);
    $bridge->ops = [];
    $answer = $review->decide('bob', 'tmp/a/back.md', 'reject', 'still mine');
    $check('X6: a restored source makes a refusal clear the request instead of handing the page to the orphan owner',
        ReviewStore::downRequestOf($store->read(), 'tmp/a/back.md') === null
        && $store->isLive('tmp/a/back.md')
        && ($answer['sourceDeleted'] ?? true) === false
        && ($answer['transferred'] ?? false) === false
        && $orphan->transfers === [],
        (string)json_encode($answer));

    // ⑥ X6 (decide side): a take-down of a page whose source is still there keeps its landing point
    $trees->publish('tmp/a/taken.md', 100);
    $files->authorFiles['a:Pages/taken.md'] = "# taken\n";
    $answer = $review->takeDown('bob', 'tmp/a/taken.md', 'off the site');
    $check('X6: takeDown() computes sourceDeleted from the file and keeps the entry while the source exists',
        !$store->isLive('tmp/a/taken.md')
        && ($answer['sourceDeleted'] ?? true) === false
        && ($answer['forgotten'] ?? true) === false
        && ($store->entry('tmp/a/taken.md')['owner'] ?? '') === 'a',
        (string)json_encode($answer));

    // ⑤ X2: the delete bookkeeping sees a target the site serves but the manifest does not list
    $trees->publish('tmp/a/onlineonly.md', 100);
    $plan = $store->requestTakeDownForDeletedSources(['tmp/a/onlineonly.md'], 901);
    $check('X2: the delete bookkeeping sees a target that is in online/ but has no manifest entry, and does not count it as forgotten',
        $plan === ['requested' => ['tmp/a/onlineonly.md'], 'forgotten' => [], 'failed' => []]
        && ReviewStore::downRequestOf($store->read(), 'tmp/a/onlineonly.md') === ['at' => 901, 'sourceDeleted' => true]
        && $store->isLive('tmp/a/onlineonly.md'),
        (string)json_encode($plan));

    // ⑤ C4: a target only pending/ knows has no entry to delete: it goes, but it is not "forgotten"
    $trees->freeze('tmp/a/pendingonly.md', 100);
    $plan = $store->requestTakeDownForDeletedSources(['tmp/a/pendingonly.md'], 902);
    $check('C4: a target that only pending/ knew is not counted as a forgotten entry',
        $plan === ['requested' => [], 'forgotten' => [], 'failed' => []]
        && !$store->isPending('tmp/a/pendingonly.md')
        && !isset($store->read()['entries']['tmp/a/pendingonly.md']),
        (string)json_encode($plan));

    // ⑥ X6: a rename is a delete of the old address - file form
    $renameConfig = new FakeConfig();
    $renameConfig->setAppValue('pages', 'site', (string)json_encode(['nc_user' => 'siteuser', 'nc_path' => '/Sites/site']));
    $renameLogger = new FakeLogger();
    $renameBridge = new BridgeClient();
    $renameRegistry = new SiteRegistry($renameConfig, new FakeCrypto(), new L10N(), new FakeUserManager());
    ['store' => $renameStore, 'trees' => $renameTrees] = $build();
    $rename = new AuthorDeltaService(
        $renameRegistry,
        $renameBridge,
        new \OCA\Pages\Service\AuthorSourcesService(),
        $renameConfig,
        $renameLogger,
        $renameStore,
        new DeltaQueue($renameConfig, $renameLogger, new FakeLocking()),
    );
    $renameTrees->publish('tmp/alice/one.md', 100);
    $rename->renamed(new \OCP\Files\Node('/alice/files/Pages/one.md'), new \OCP\Files\Node('/alice/files/Pages/two.md'));
    $renamedEntry = $renameStore->entry('tmp/alice/one.md');
    $check('X6: renaming a served file writes a take-off request on the old target and leaves the new one a draft',
        ($renamedEntry['owner'] ?? '') === 'alice'
        && ($renamedEntry['downRequest']['sourceDeleted'] ?? null) === true
        && ($renamedEntry['downRequest']['at'] ?? 0) > 0
        && !isset($renameStore->read()['entries']['tmp/alice/two.md'])
        && array_map(
            static fn (array $delta): string => ($delta['op'] ?? '') . ' ' . ($delta['target'] ?? ''),
            $renameBridge->authorDeltas,
        ) === ['remove tmp/alice/one.md', 'add tmp/alice/two.md'],
        (string)json_encode($renameBridge->authorDeltas));

    // ⑥ X6: a folder rename records every served page under the old prefix, then sends one mirror
    $renameTrees->publish('tmp/alice/folder/a.md', 100);
    $renameTrees->publish('tmp/alice/folder/b.md', 101);
    $renameBridge->authorDeltas = [];
    $rename->renamed(
        new \OCP\Files\FakeFolderNode('/alice/files/Pages/folder'),
        new \OCP\Files\FakeFolderNode('/alice/files/Pages/folder2'),
    );
    $renamedManifest = $renameStore->read();
    $check('X6: a folder rename asks for every served page under the old prefix to be taken down',
        (ReviewStore::downRequestOf($renamedManifest, 'tmp/alice/folder/a.md')['sourceDeleted'] ?? null) === true
        && (ReviewStore::downRequestOf($renamedManifest, 'tmp/alice/folder/b.md')['sourceDeleted'] ?? null) === true
        && !isset($renamedManifest['entries']['tmp/alice/folder2/a.md'])
        && count($renameBridge->authorDeltas) === 1
        && ($renameBridge->authorDeltas[0]['op'] ?? '') === 'mirror',
        (string)json_encode($renameBridge->authorDeltas));

    // ⑧ X8: deleting an account records a take-off request for the pages it still serves
    $renameTrees->publish('tmp/carol/c.md', 102);
    $rename->userDeleted('carol');
    $afterDelete = $renameStore->read();
    $check('X8: deleting an account asks for every served page under its author prefix to be taken down',
        (ReviewStore::downRequestOf($afterDelete, 'tmp/carol/c.md')['sourceDeleted'] ?? null) === true
        && (ReviewStore::downRequestOf($afterDelete, 'tmp/carol/c.md')['at'] ?? 0) > 0
        && $renameStore->isLive('tmp/carol/c.md'),
        (string)json_encode(ReviewStore::downRequestOf($afterDelete, 'tmp/carol/c.md')));

    echo "33. an unconfirmed snapshot is refused by the state-changing paths\n";
    /*
     * The Files API fallback keeps the read paths working, but it is not evidence that nothing is
     * live: a bridge-written file may not be in the filecache yet. The offline request, a decision
     * and a direct take-down therefore answer 409 BRIDGE_UNKNOWN instead of acting on a guess, while
     * the display paths keep the fallback answer.
     */
    ['review' => $review, 'store' => $store, 'trees' => $trees, 'articles' => $articles,
        'files' => $files, 'bridge' => $bridge, 'locking' => $locking] = $makeReview();
    $articles->rows['/tmp/a/one.md'] = new Article('/tmp/a/one.md', Article::STATE_EXTERNAL, null, 0, 0, '', 'a');
    $files->authorFiles['a:Pages/one.md'] = "# one\n";
    $trees->publish('tmp/a/one.md', 100);
    $bridge->pathsFail = new BridgeException('the bridge is down', 'UNREACHABLE');
    $trees->forget();

    $check('a read-only question keeps the fallback answer, marked untrusted',
        $store->counts() === ['pending' => 0, 'online' => 0]
        && $store->isLive('tmp/a/one.md') === false
        && $store->treesTrusted() === false,
        (string)json_encode($store->counts()));

    $bridge->ops = [];
    $refused = null;
    try {
        $review->request('a', 'tmp/a/one.md', 'offline', false);
    } catch (ConflictException $e) {
        $refused = $e;
    }
    $check('request(offline) answers 409 BRIDGE_UNKNOWN while the tree is unconfirmed',
        $refused instanceof ConflictException
        && ($refused->getDetails()['reason'] ?? '') === 'BRIDGE_UNKNOWN'
        && str_contains($refused->getMessage(), 'could not be confirmed')
        && $bridge->ops === []
        && ReviewStore::downRequestOf($store->read(), 'tmp/a/one.md') === null,
        $refused === null ? 'no exception' : $refused->getMessage());

    $store->setFields(['tmp/a/one.md'], ['owner' => 'a', 'downRequest' => ['at' => 5, 'sourceDeleted' => false]]);
    $refused = null;
    try {
        $review->decide('bob', 'tmp/a/one.md', 'reject', 'no');
    } catch (ConflictException $e) {
        $refused = $e;
    }
    $check('decide() answers 409 BRIDGE_UNKNOWN while the tree is unconfirmed',
        $refused instanceof ConflictException
        && ($refused->getDetails()['reason'] ?? '') === 'BRIDGE_UNKNOWN'
        && $bridge->ops === []
        && ReviewStore::downRequestOf($store->read(), 'tmp/a/one.md') !== null
        && $locking->held() === [],
        $refused === null ? 'no exception' : $refused->getMessage());

    $refused = null;
    try {
        $review->takeDown('bob', 'tmp/a/one.md', 'off');
    } catch (ConflictException $e) {
        $refused = $e;
    }
    $check('takeDown() answers 409 BRIDGE_UNKNOWN while the tree is unconfirmed',
        $refused instanceof ConflictException
        && ($refused->getDetails()['reason'] ?? '') === 'BRIDGE_UNKNOWN'
        && $bridge->ops === []
        && $store->treesTrusted() === false
        && $locking->held() === [],
        $refused === null ? 'no exception' : $refused->getMessage());

    $bridge->pathsFail = null;
    $trees->forget();
    $check('the bridge answering again restores trust and the write paths',
        $store->treesTrusted() === true && $store->isLive('tmp/a/one.md'));

    echo "34. cancel() runs the same authoring gate as request()\n";
    /*
     * `request()` checks the authoring grant at the endpoint; `cancel()` used to reach the service
     * with only `siteContext('view')`, so a user outside the grant got the service's ownership error
     * instead of the endpoint's FORBIDDEN. The reviewer exception is the one `request()` grants, and
     * it must work here too.
     */
    $cancelRegistry = new FakeSiteRegistry(
        ['nc_user' => 'siteuser', 'nc_path' => '/Sites/site'],
        ['authors'],
        ['reviewers'],
    );
    ['review' => $review, 'store' => $store, 'trees' => $trees, 'articles' => $articles,
        'files' => $files, 'bridge' => $bridge] = $makeReview($cancelRegistry);
    $articles->rows['/tmp/a/one.md'] = new Article('/tmp/a/one.md', Article::STATE_INTERNAL, null, 0, 0, '', 'a');
    $files->authorFiles['a:Pages/one.md'] = "# one\n";
    $trees->freeze('tmp/a/one.md', 100);
    $store->setFields(['tmp/a/one.md'], ['owner' => 'a', 'candidateDigest' => $bridge->candidateDigest]);
    $cancelRequest = new FakeRequest(['path' => 'tmp/a/one.md']);

    $outcast = new ReviewController(
        $cancelRequest,
        new FakeUserSession('mallory'),
        new FakeGroupManager(false, []),
        $cancelRegistry,
        $files,
        $bridge,
        new L10N(),
        $review,
    );
    $bridge->ops = [];
    $answer = $outcast->cancel();
    $check('cancel() answers 403 FORBIDDEN without the authoring grant',
        $answer->getStatus() === 403 && ($answer->getData()['error'] ?? '') === 'FORBIDDEN',
        (string)json_encode($answer->getData()));
    $check('and the waiting candidate is untouched', $store->isPending('tmp/a/one.md') && $bridge->ops === []);

    $bridge->onOp = static function (string $op, string $target) use ($trees): void {
        if ($op === 'discard') {
            $trees->drop($target);
        }
    };
    $reviewer = new ReviewController(
        $cancelRequest,
        new FakeUserSession('rev'),
        new FakeGroupManager(false, ['reviewers']),
        $cancelRegistry,
        $files,
        $bridge,
        new L10N(),
        $review,
    );
    $bridge->ops = [];
    $answer = $reviewer->cancel();
    $check('a reviewer passes the authoring gate and withdraws the request',
        $answer->getStatus() === 200
        && ($answer->getData()['cancelled'] ?? false) === true
        && !$store->isPending('tmp/a/one.md')
        && $bridge->ops === [['op' => 'discard', 'uid' => 'a', 'target' => 'tmp/a/one.md']],
        (string)json_encode([$answer->getData(), $bridge->ops]));

    echo "35. a failed author-root move does not claim that nothing changed when the rollback left files behind\n";
    /*
     * Both controllers inherit the helper from BaseController (they share that base class), so one
     * assertion covers both call sites. The bridge's all-or-nothing move normally leaves nothing
     * changed; `rollback_failed` names the files it could not put back, and those are still under the
     * new name - the answer must say so rather than keep the old sentence.
     */
    $failureController = new class(
        'pages',
        new FakeRequest(),
        new FakeUserSession('admin'),
        new FakeGroupManager(),
        new FakeSiteRegistry(),
        new FileService(),
        new BridgeClient(),
        new L10N(),
    ) extends \OCA\Pages\Controller\BaseController {
        public function failedMove(\Throwable $e): \Throwable
        {
            return $this->authorRootMoveFailed($e);
        }
    };

    $moveDetails = [
        'moved' => ['online' => 4, 'pending' => 1],
        'failed' => ['tmp/a/two.md'],
        'remaining' => ['tmp/a/two.md', 'tmp/a/three.md'],
        'rollback_failed' => ['tmp/new/a.md', 'tmp/new/b.md', 'tmp/new/c.md', 'tmp/new/d.md'],
    ];
    $failedMove = $failureController->failedMove(new BridgeException(
        'the author content directory was not changed: some content under the old name could not be moved',
        'AUTHOR_ROOT_MOVE_FAILED',
        $moveDetails,
        409,
    ));
    $moveMessage = $failedMove->getMessage();
    $check('C3: rollback failures are named instead of claiming that nothing was changed',
        $failedMove instanceof BridgeException
        && $failedMove->getBridgeCode() === 'AUTHOR_ROOT_MOVE_FAILED'
        && $failedMove->getHttpStatus() === 409
        && $failedMove->getDetails() === $moveDetails
        && !str_contains($moveMessage, 'nothing was changed')
        && str_contains($moveMessage, '1 file(s) were put back')
        && str_contains($moveMessage, '4 file(s) are still under the new name')
        && str_contains($moveMessage, 'tmp/new/a.md')
        && str_contains($moveMessage, 'tmp/new/c.md')
        && !str_contains($moveMessage, 'tmp/new/d.md'),
        $moveMessage);

    $cleanDetails = ['moved' => ['online' => 2, 'pending' => 0], 'rollback_failed' => []];
    $cleanMove = $failureController->failedMove(new BridgeException(
        'the files that did move were put back',
        'AUTHOR_ROOT_MOVE_FAILED',
        $cleanDetails,
        409,
    ));
    $check('C3: a move whose rollback left nothing behind keeps the old wording',
        str_contains($cleanMove->getMessage(), 'nothing was changed')
        && $cleanMove->getDetails() === $cleanDetails,
        $cleanMove->getMessage());

    echo "36. a scanned row reports the source it just found, not the request's old flag\n";
    /*
     * `ArticleReviewService::decide()` reads whether the file is there at decision time; the index row
     * is the display half of the same fact. A `sourceDeleted: true` request whose file came back must
     * not show "source deleted" on the row the scan built from that file.
     */
    loadRealArticleIndexService();
    ['store' => $indexStore, 'files' => $indexFiles, 'articles' => $indexArticles] = $build();
    $indexStore->setFields(['tmp/a/back.md'], [
        'owner' => 'a',
        'downRequest' => ['at' => 5, 'sourceDeleted' => true],
    ]);
    $indexFiles->authorFiles['a:Pages/back.md'] = "# back\n";
    $indexFiles->trees['a:Pages'] = [['type' => 'file', 'path' => 'back.md']];
    $indexAuthorSources = new \OCA\Pages\Service\AuthorSourcesService();
    $indexAuthorSources->authors = ['a'];
    $index = new \OCA\Pages\Service\ProbeRealArticleIndexService(
        new FakeSiteRegistry(),
        $indexFiles,
        $indexArticles,
        $indexStore,
        new \OCA\Pages\Db\VoteMapper(),
        new \OCA\Pages\Db\CommentMapper(),
        $indexAuthorSources,
        new FakeUserManager(),
        new AuthorAreaService(),
        new \OCA\Pages\Service\PageUrlService(),
    );
    $row = $index->single('a', 'tmp/a/back.md', true);
    $check('C2: a row built from an existing source reports sourceDeleted false even when the request recorded true',
        is_array($row)
        && ($row['downRequest'] ?? false) === true
        && ($row['sourceDeleted'] ?? true) === false,
        $row === null ? 'no row' : (string)json_encode($row));

    echo "37. a folder-level lock is retried, and the backup is taken only once\n";
    /*
     * The live failure was not a locked file but a locked *folder*: clear() falls back to deleting and
     * re-creating the site folder, and either half of that can throw LockedException. The replacement
     * now retries the whole ensure/clear/write sequence, but the backup stays outside the loop so a
     * retry cannot produce a second backup. The three runs below pin the success, the exhausted-lock
     * failure (with its rollback) and a non-lock failure that must not be retried.
     */
    $replacementProbe = function (array $old): array {
        $appData = new ReplacementAppData();
        $files = new ReplacementFiles();
        $logger = new FakeLogger();
        $service = new \OCA\Pages\Service\SourceReplacementService($appData, $files, new L10N(), $logger);
        $site = $files->folderAtOrCreate('/Sites/site/source');
        foreach ($old as $name => $content) {
            $site->newFile($name, $content);
        }
        $files->ensureCalls = 0;

        return ['appData' => $appData, 'files' => $files, 'logger' => $logger, 'service' => $service, 'site' => $site];
    };
    $retried = static function (FakeLogger $logger): array {
        return array_values(array_filter(
            $logger->lines,
            static fn (array $line): bool => $line['message'] === 'pages: the site folder was locked, retrying the replacement',
        ));
    };

    ['appData' => $lockAppData, 'files' => $lockFiles, 'logger' => $lockLogger,
        'service' => $lockService, 'site' => $lockSite] = $replacementProbe(['old.md' => "old\n"]);
    // first attempt: the child delete finds the lock, and the folder rebuild it falls back to is
    // locked too - exactly the pair the live error came from
    $lockSite->children['old.md']->deleteLockTimes = 1;
    $lockSite->deleteLockTimes = 1;
    $lockResult = null;
    $lockError = null;
    try {
        $lockResult = $lockService->replace('siteuser', '/Sites/site/source', ['index.html' => "new\n"], 'pull');
    } catch (\Throwable $e) {
        $lockError = $e;
    }
    $retryLines = $retried($lockLogger);
    $check('37a: a short folder lock is waited out, not reported',
        $lockError === null
        && $lockResult !== null
        && $lockResult['files'] === 1
        && $lockResult['bytes'] === 4,
        $lockError === null ? (string)json_encode($lockResult) : $lockError->getMessage());
    $check('37a: the new tree is in place and the old one is gone',
        $lockSite->file('index.html')?->content === "new\n"
        && $lockSite->file('old.md') === null
        && $lockFiles->writes === [['rel' => 'index.html', 'content' => "new\n"]],
        (string)json_encode($lockFiles->writes));
    $check('37a: the backup was taken once and no rollback ran',
        $lockResult !== null
        && count($lockAppData->backups()) === 1
        && $lockResult['backup']['files'] === 1
        && $lockFiles->ensureCalls === 2,
        (string)json_encode(['backups' => $lockAppData->backups(), 'ensure' => $lockFiles->ensureCalls]));
    $check('37a: the retry was logged with its attempt and path',
        count($retryLines) === 1
        && $retryLines[0]['context']['attempt'] === 1
        && $retryLines[0]['context']['path'] === '/Sites/site/source'
        && array_key_exists('exception', $retryLines[0]['context']),
        (string)json_encode($retryLines));

    ['appData' => $deadAppData, 'files' => $deadFiles, 'logger' => $deadLogger,
        'service' => $deadService, 'site' => $deadSite] = $replacementProbe(['old.md' => "old\n"]);
    // every attempt of the replacement (the initial one plus the three configured retries) is locked
    $deadSite->children['old.md']->deleteLockTimes = 4;
    $deadSite->deleteLockTimes = 4;
    $deadError = null;
    try {
        $deadService->replace('siteuser', '/Sites/site/source', ['index.html' => "new\n"], 'pull');
    } catch (\Throwable $e) {
        $deadError = $e;
    }
    $deadLines = $retried($deadLogger);
    $check('37b: four locked attempts raise SourceReplacementException',
        $deadError instanceof \OCA\Pages\Service\SourceReplacementException
        && $deadError->isRolledBack() === true
        && ($deadError->getBackup()['path'] ?? '') !== '',
        $deadError === null ? 'no exception' : $deadError->getMessage());
    $check('37b: the rollback put the backup back and left no new content',
        $deadSite->file('old.md')?->content === "old\n"
        && $deadSite->file('index.html') === null
        && $deadFiles->writes === [['rel' => 'old.md', 'content' => "old\n"]],
        (string)json_encode($deadFiles->writes));
    $check('37b: exactly three retries were logged, and one backup was taken',
        count($deadLines) === 3
        && array_column(array_column($deadLines, 'context'), 'attempt') === [1, 2, 3]
        && count($deadAppData->backups()) === 1
        && $deadFiles->ensureCalls === 5,
        (string)json_encode(['retries' => count($deadLines), 'ensure' => $deadFiles->ensureCalls]));

    ['appData' => $plainAppData, 'files' => $plainFiles, 'logger' => $plainLogger,
        'service' => $plainService, 'site' => $plainSite] = $replacementProbe(['old.md' => "old\n"]);
    // not a lock: the first write is refused, and waiting cannot change that
    $plainFiles->writeError = new \OCP\Files\NotPermittedException('no write permission');
    $plainError = null;
    try {
        $plainService->replace('siteuser', '/Sites/site/source', ['index.html' => "new\n"], 'pull');
    } catch (\Throwable $e) {
        $plainError = $e;
    }
    $check('37c: a non-lock failure is not retried',
        $plainError instanceof \OCA\Pages\Service\SourceReplacementException
        && $plainError->getPrevious() instanceof \OCP\Files\NotPermittedException
        && $retried($plainLogger) === []
        && $plainFiles->ensureCalls === 2,
        $plainError === null ? 'no exception' : $plainError->getMessage());
    $check('37c: it still rolls back, from the one backup',
        $plainError instanceof \OCA\Pages\Service\SourceReplacementException
        && $plainError->isRolledBack() === true
        && $plainSite->file('old.md')?->content === "old\n"
        && $plainSite->file('index.html') === null
        && count($plainAppData->backups()) === 1,
        (string)json_encode($plainFiles->writes));

    echo "\n$pass passed, $fail failed\n";
    exit($fail > 0 ? 1 : 0);
}
