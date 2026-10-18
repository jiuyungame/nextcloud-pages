<?php

declare(strict_types=1);

namespace OCA\Pages\Controller;

use OCA\Pages\AppInfo\Application;
use OCA\Pages\Service\ArticleIndexService;
use OCA\Pages\Service\AuthorAreaService;
use OCA\Pages\Service\BridgeClient;
use OCA\Pages\Service\BridgeException;
use OCA\Pages\Service\ConflictException;
use OCA\Pages\Service\FileService;
use OCA\Pages\Service\FrontMatter;
use OCA\Pages\Service\PageUrlService;
use OCA\Pages\Service\ReviewStore;
use OCA\Pages\Service\SiteRegistry;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * File browsing and editing. Everything goes through FileService (Nextcloud Files API), so version
 * history, trash and desktop sync keep working; a successful save notifies the bridge to rebuild.
 *
 * Two shapes of the same operations, and the **path decides** which one a request is:
 *  - the **site tree**: a site-relative file path (`_config.yml`, `assets/…`), reachable with the
 *    publish grant. `GET /api/site/tree?scope=site` is its tree, and the source page is the one
 *    place it belongs;
 *  - the **author area**: a site target (`<author_root>/<uid>/…`), which every author addresses -
 *    the site's storage user and an administrator exactly like anybody else.
 *    `GET /api/site/tree?scope=author` is its tree, and the content page's column is where it
 *    belongs. A target that names another author is answered 403 by the mapper, so no endpoint here
 *    reaches a foreign folder.
 *
 * An author's files are pages of the site's template, never posts: the only rule that differs between
 * an author file and a site file is where it is rendered (see PageUrlService), not what it is.
 */
class BrowseController extends BaseController
{
    /**
     * The front-matter fields the structured editor owns. A save writes these and nothing else, which is
     * what keeps `layout`, `permalink` and every key this app has never heard of in the file.
     */
    private const META_OWNED = ['title', 'tags', 'date', 'categories'];

    /** How many categories one page may carry; the site mirrors one copy per usable category. */
    private const MAX_CATEGORIES = 5;

    /**
     * A category name: lower-case letters, digits, underscore and hyphen - and every non-ASCII
     * character, because a Chinese category is as valid a folder name as a Chinese slug is. The site
     * then requires a page of that name at its root (see PageUrlService::isCategoryName()).
     */
    private const CATEGORY_NAME = '/^[a-z0-9_\-\x{80}-\x{10FFFF}]+$/u';

    /**
     * Fields of the answer that are read-only for the client: `modified_date` is the server's own stamp,
     * the rest describe the file or are computed. They are accepted and ignored rather than refused, so
     * a client that sends back the meta object it was given is not punished for echoing.
     */
    private const META_READ_ONLY = ['modified_date', 'author', 'permalink', 'keys'];

    /**
     * Any other key the panel may write: lower-case letters, digits and underscore, at most 64
     * characters - the shape a Jekyll front-matter key can take without quoting, and short enough that a
     * typo stays visible. Values are scalars or a list of strings (see metaEdit()).
     */
    private const META_EXTRA_KEY = '/^[a-z0-9_]+$/';
    private const META_EXTRA_KEY_MAX = 64;

    public function __construct(
        IRequest $request,
        IUserSession $userSession,
        IGroupManager $groupManager,
        SiteRegistry $registry,
        FileService $files,
        BridgeClient $bridge,
        IL10N $l10n,
        private ArticleIndexService $articleIndex,
        private PageUrlService $pageUrls,
        private ReviewStore $review,
    ) {
        parent::__construct(Application::APP_ID, $request, $userSession, $groupManager, $registry, $files, $bridge, $l10n);
    }

    /**
     * Every method here is gated inside the controller (siteContext), so the framework-level admin
     * gate has to be lifted for the route to be reachable at all.
     */
    #[NoAdminRequired]
    public function tree(string $scope = ''): DataResponse
    {
        // Which tree the caller asked for. It is explicit because the two pages of this app show
        // different things to the same user: the content page's column is always the caller's own
        // author area (the site's storage user and an administrator included), the source page's is
        // always the site. Without the parameter the answer is the caller's default - the site tree
        // for a publisher, the own area otherwise - which is what a client written before it got.
        $scope = strtolower(trim($scope));
        if ($scope !== '' && $scope !== 'author' && $scope !== 'site') {
            return new DataResponse(
                ['error' => 'BAD_REQUEST', 'message' => $this->l10n->t('The scope must be author or site')],
                400,
            );
        }

        if ($scope === 'site' || ($scope === '' && $this->canEdit())) {
            [$denied, $site, $userId] = $this->siteContext('edit');
            if ($denied !== null) {
                return $denied;
            }
            try {
                $result = $this->files->tree($userId, (string)$site['nc_path']);
                return new DataResponse([
                    'source' => $site['nc_path'],
                    'exists' => true,
                    ...$result,
                ]);
            } catch (\Throwable $e) {
                if ($this->files->siteExists($userId, (string)$site['nc_path']) === false) {
                    // directory not created yet: "not initialised", not an error
                    return new DataResponse([
                        'source' => $site['nc_path'],
                        'exists' => false,
                        'nodes' => [],
                        'truncated' => false,
                        'hint' => $this->l10n->t('The site directory does not exist yet. Run init on the bridge first (it back-fills the working copy).'),
                    ]);
                }
                return $this->translate($e) ?? throw $e;
            }
        }

        // the author scope: the caller's own area is the whole world this answer may cover, for
        // every caller - the storage user of the site and an administrator included
        if (($missing = $this->requireSite()) !== null) {
            return $missing;
        }
        if (($author = $this->requireAuthor()) !== null) {
            return $author;
        }
        $areas = $this->authorAreas();
        $uid = $this->currentUserId();
        $area = '/' . $areas->area();
        $nodes = [];
        $exists = true;
        $truncated = false;
        // one read of the manifest for the whole column: the badge of every node comes from it, and
        // a per-node request would make a folder of twenty files cost twenty round trips
        $manifest = $this->review->read();
        try {
            $result = $this->files->tree($uid, $areas->area());
            // the whole author tree, folders included: this column is where an author works, and
            // every operation (create, rename, delete, open) starts on one of these nodes
            $nodes = array_map(
                fn (array $node): array => $this->withTarget($uid, $node, $manifest),
                $result['nodes'],
            );
            $truncated = (bool)$result['truncated'];
        } catch (\Throwable) {
            // an area that is not there yet is "not initialised": the first save creates it
            $exists = false;
        }

        return new DataResponse([
            'source' => $area,
            'scope' => 'author',
            'owner' => $uid,
            // the prefix every node target carries, so a client can read a path back out of one
            'target_prefix' => '/' . $areas->targetPrefix($uid),
            'exists' => $exists,
            'nodes' => $nodes,
            'truncated' => $truncated,
            'hint' => $exists
                ? null
                : $this->l10n->t('Your author area %s does not exist yet; the first file you save creates it.', [$area]),
            // the nodes carry targets, so the client needs no knowledge of the mapping
            'targets' => true,
        ]);
    }

    #[NoAdminRequired]
    public function read(string $path = ''): DataResponse
    {
        if ($this->isAuthorScoped($path)) {
            return $this->authorCall(function () use ($path): DataResponse {
                $areas = $this->authorAreas();
                $uid = $this->currentUserId();
                $resolved = $areas->resolve($uid, $path);
                $deny = $this->denyReason($resolved, $uid);
                if ($deny !== null) {
                    return $this->authorDenied($deny, $path);
                }
                $file = $this->files->read($uid, $areas->area(), $resolved['file']);

                return new DataResponse([...$file, 'path' => $resolved['target'], 'owner' => $uid]);
            });
        }
        [$denied, $site, $userId] = $this->siteContext('edit');
        if ($denied !== null) {
            return $denied;
        }
        try {
            return new DataResponse($this->files->read($userId, (string)$site['nc_path'], $path));
        } catch (\Throwable $e) {
            return $this->translate($e) ?? throw $e;
        }
    }

    /**
     * Writes a whole file: `content` for a text file, `contentBase64` for anything else.
     *
     * The base64 spelling exists for the author tree's "replace" action: an image or another resource
     * is a reviewed entry of the author's area exactly like a page, and a JSON body cannot carry its
     * bytes. Both spellings end in the same write, so the etag lock, the ownership rules and the
     * bridge notification are the ones this endpoint always had.
     */
    #[NoAdminRequired]
    public function write(string $path = '', string $content = '', ?string $baseEtag = null): DataResponse
    {
        $encoded = $this->request->getParam('contentBase64', null);
        if ($encoded !== null) {
            if (!is_string($encoded)) {
                return new DataResponse(
                    ['error' => 'BAD_REQUEST', 'message' => $this->l10n->t('The contentBase64 field must be text')],
                    400,
                );
            }
            $decoded = base64_decode($encoded, true);
            if ($decoded === false) {
                return new DataResponse(
                    ['error' => 'BAD_REQUEST', 'message' => $this->l10n->t('The contentBase64 field is not valid base64')],
                    400,
                );
            }
            $content = $decoded;
        }
        if ($this->isAuthorScoped($path)) {
            return $this->authorCall(function () use ($path, $content, $baseEtag): DataResponse {
                $areas = $this->authorAreas();
                $uid = $this->currentUserId();
                $resolved = $areas->resolve($uid, $path);
                $deny = $this->denyReason($resolved, $uid);
                if ($deny !== null) {
                    return $this->authorDenied($deny, $path);
                }
                $result = $this->files->write($uid, $areas->area(), $resolved['file'], $content, $baseEtag);
                $this->registerArticle($uid, $areas->area(), $resolved);

                return new DataResponse([
                    ...$result,
                    'path' => $resolved['target'],
                    'owner' => $uid,
                    'bridge' => $this->syncAuthorSources(),
                ]);
            });
        }
        [$denied, $site, $userId] = $this->siteContext('edit');
        if ($denied !== null) {
            return $denied;
        }
        try {
            $result = $this->files->write(
                $userId,
                (string)$site['nc_path'],
                $path,
                $content,
                $baseEtag,
                false,
            );
        } catch (\Throwable $e) {
            return $this->translate($e) ?? throw $e;
        }

        return new DataResponse([
            ...$result,
            'bridge' => $this->notifyBridge(),
        ]);
    }

    /**
     * One article as the structured editor sees it: the body as text and the front matter as fields.
     *
     * The split happens here, on the server, because the block carries keys this app does not own
     * (`layout`, `permalink`, categories, …) and a client that rendered the whole
     * file would drop the ones it does not model on the next save. Reading a *file* stays
     * `GET /api/site/file`; this route answers for an article, with the same targets and the same
     * ownership rules as every other article endpoint.
     */
    #[NoAdminRequired]
    public function articleContent(): DataResponse
    {
        try {
            $path = trim($this->textField('path') ?? '');
        } catch (\InvalidArgumentException $e) {
            return $this->translate($e) ?? throw $e;
        }
        if ($path === '') {
            return new DataResponse(['error' => 'BAD_REQUEST', 'message' => $this->l10n->t('A path is required')], 400);
        }

        // The path decides where the article is, the capability decides how far the caller reaches: a
        // path that names an author area (`<author_root>/<uid>/…`, the retired `_posts/<uid>/…` too)
        // is an article of that area, and a path that names nobody is a file of the site skeleton. An
        // author is refused a skeleton path by the mapper (403, like the file endpoints); an editor
        // reaches both.
        if ($this->isAuthorScoped($path)) {
            return $this->authorCall(function () use ($path): DataResponse {
                $areas = $this->authorAreas();
                $article = $this->resolveArticle($path);
                if ($article['deny'] !== null) {
                    return $article['deny'];
                }

                return new DataResponse($this->articleAnswer(
                    $article['target'],
                    $this->files->read($article['uid'], $areas->area(), $article['file']),
                ));
            });
        }

        $target = $this->articleTarget($path);
        if ($target === null) {
            return new DataResponse(
                ['error' => 'BAD_REQUEST', 'message' => $this->l10n->t('Not an article path: %s', [$path])],
                400,
            );
        }
        [$denied, $site, $userId] = $this->siteContext('edit');
        if ($denied !== null) {
            return $denied;
        }
        try {
            return new DataResponse($this->articleAnswer(
                $target,
                $this->files->read($userId, (string)$site['nc_path'], $target),
            ));
        } catch (\Throwable $e) {
            return $this->translate($e) ?? throw $e;
        }
    }

    /**
     * Saves an article: the body as sent plus the fields the client names.
     *
     *   {"path": "/_posts/alice/2026-05-01-hello.md", "body": "…", "baseEtag": "…",
     *    "meta": {"title": "…", "tags": ["a","b"], "date": "2026-05-01", "excerpt": "…"}}
     *
     * The document is rebuilt on the server, from the file that is on disk: a key the request does not
     * name keeps its value *and its line*, in its own place in the block. That is the whole reason the
     * client sends fields instead of a whole file — the block holds keys the panel does not show, and
     * none of them may be lost by a save that only edited a title.
     *
     * `''` (or `[]` for tags) removes a key, `date` is written only when the client names it (Jekyll
     * takes a post's date from the file name otherwise) and has to be an ISO day no later than today,
     * and `modified_date` is always the server's: it is stamped on every accepted save and never taken
     * from the request.
     */
    #[NoAdminRequired]
    public function articleSave(): DataResponse
    {
        try {
            $path = trim($this->textField('path') ?? '');
            if ($path === '') {
                throw new \InvalidArgumentException($this->l10n->t('A path is required'));
            }
            // a missing body is refused instead of read as "": a request that forgot the text must not
            // empty the article, while an explicitly empty body is a legitimate (destructive) save
            $body = $this->textField('body');
            if ($body === null) {
                throw new \InvalidArgumentException($this->l10n->t('A body is required'));
            }
            $baseEtag = $this->textField('baseEtag');
            [$set, $remove] = $this->metaEdit();
        } catch (\InvalidArgumentException $e) {
            return $this->translate($e) ?? throw $e;
        }
        // the one field the server owns, in the instance's own time; it travels into `update()` like any
        // other value, so an existing key is rewritten in place and a missing one is appended
        $set['modified_date'] = date('Y-m-d H:i:s O');

        if ($this->isAuthorScoped($path)) {
            return $this->authorCall(function () use ($path, $body, $baseEtag, $set, $remove): DataResponse {
                $areas = $this->authorAreas();
                $article = $this->resolveArticle($path);
                if ($article['deny'] !== null) {
                    return $article['deny'];
                }
                $answer = $this->saveArticle(
                    $article['uid'],
                    $areas->area(),
                    $article['file'],
                    $article['target'],
                    $set,
                    $remove,
                    $body,
                    $baseEtag,
                );
                // the bookkeeping an author's article needs on every save: the index has to know the
                // article (a brand new author is only an author once the database says so), and the
                // revision that was just replaced must not stay approved
                $this->registerArticle($article['uid'], $areas->area(), $article);

                // the area is mirrored from the author sources, so the configuration has to travel again
                // for the change to reach the working copy - whoever saved it
                return new DataResponse([...$answer, 'bridge' => $this->syncAuthorSources()]);
            });
        }

        $target = $this->articleTarget($path);
        if ($target === null) {
            return new DataResponse(
                ['error' => 'BAD_REQUEST', 'message' => $this->l10n->t('Not an article path: %s', [$path])],
                400,
            );
        }
        [$denied, $site, $userId] = $this->siteContext('edit');
        if ($denied !== null) {
            return $denied;
        }
        try {
            $answer = $this->saveArticle($userId, (string)$site['nc_path'], $target, $target, $set, $remove, $body, $baseEtag);
        } catch (\Throwable $e) {
            return $this->translate($e) ?? throw $e;
        }

        return new DataResponse([...$answer, 'bridge' => $this->notifyBridge()]);
    }

    /**
     * Creates a file. Creation is the other way a name enters the site tree, so it is held to the same
     * rule a move is (`nameDayNotAfterToday()`): the two entry points must not be able to disagree
     * about a file the app then refuses to save. That rule is about the site's own `_posts/**` only -
     * an author's file is a page, and a date in its name is a date in a name.
     */
    #[NoAdminRequired]
    public function create(string $path = '', string $content = ''): DataResponse
    {
        if ($this->isAuthorScoped($path)) {
            return $this->authorCall(function () use ($path, $content): DataResponse {
                $areas = $this->authorAreas();
                $uid = $this->currentUserId();
                $resolved = $areas->resolve($uid, $path);
                // create is the one call whose target is expected not to exist yet: asking for
                // "must exist" here refused every new article with a 404
                $deny = $this->denyReason($resolved, $uid, false);
                if ($deny !== null) {
                    return $this->authorDenied($deny, $path);
                }
                $this->nameDayNotAfterToday($resolved['target']);
                // create stays create-only: an existing file is a conflict the author has to resolve
                if ($this->authorFileExists($uid, $areas->area(), $resolved['file'])) {
                    throw new ConflictException(
                        $this->l10n->t('File already exists: %s', [$resolved['target']]),
                        ['path' => $resolved['target'], 'exists' => true],
                    );
                }
                // the first article an author saves creates their area: without this the write below
                // fails on a folder that does not exist yet (which the tree advertises as normal)
                $areas->ensureArea($uid);
                $result = $this->files->write($uid, $areas->area(), $resolved['file'], $content, null, true);
                $this->registerArticle($uid, $areas->area(), $resolved);

                return new DataResponse([
                    ...$result,
                    'path' => $resolved['target'],
                    'owner' => $uid,
                    'bridge' => $this->syncAuthorSources(),
                ]);
            });
        }
        [$denied, $site, $userId] = $this->siteContext('edit');
        if ($denied !== null) {
            return $denied;
        }
        try {
            $this->nameDayNotAfterToday($path);
            $result = $this->files->write(
                $userId,
                (string)$site['nc_path'],
                $path,
                $content,
                null,
                true,
            );
        } catch (\Throwable $e) {
            return $this->translate($e) ?? throw $e;
        }
        return new DataResponse([...$result, 'bridge' => $this->notifyBridge()]);
    }

    /**
     * Deletes a file or a folder through Nextcloud's own delete, so the trash keeps a copy.
     *
     * Deleting is direct - there is no reviewed deletion any more. What follows the delete is the
     * bookkeeping: the manifest entry of a file goes with it (nothing is left to request), while the
     * `published` list keeps the path, which is how the next publish learns to take the page off the
     * site (see ArticleReviewService::classify()). A folder takes the entries of everything inside it.
     */
    #[NoAdminRequired]
    public function delete(string $path = ''): DataResponse
    {
        if ($this->isAuthorScoped($path)) {
            return $this->authorCall(function () use ($path): DataResponse {
                $areas = $this->authorAreas();
                $uid = $this->currentUserId();
                $resolved = $areas->resolve($uid, $path);
                $deny = $this->denyReason($resolved, $uid);
                if ($deny !== null) {
                    return $this->authorDenied($deny, $path);
                }
                $isFolder = $this->authorNodeIsFolder($uid, $areas->area(), (string)$resolved['file']);
                $this->files->delete($uid, $areas->area(), (string)$resolved['file']);
                // the entries under the deleted node go with it: nothing is left to request, and the
                // next publish carries the removal of whatever the site still names
                $bookkeeping = $isFolder
                    ? $this->forgetAuthorEntries((string)$resolved['target'])
                    : $this->forgetArticleEntry((string)$resolved['target']);

                return new DataResponse([
                    'path' => $resolved['target'],
                    'owner' => $uid,
                    'deleted' => true,
                    'manifest' => $bookkeeping,
                    'bridge' => $this->syncAuthorSources([(string)$resolved['target']]),
                ]);
            });
        }
        [$denied, $site, $userId] = $this->siteContext('edit');
        if ($denied !== null) {
            return $denied;
        }
        try {
            $this->files->delete($userId, (string)$site['nc_path'], $path);
        } catch (\Throwable $e) {
            return $this->translate($e) ?? throw $e;
        }
        return new DataResponse(['path' => $path, 'deleted' => true, 'bridge' => $this->notifyBridge()]);
    }

    /**
     * Creates a folder. There is no separate delete-folder endpoint: `Node::delete()` covers files
     * and folders alike, only the front-end confirmation text differs.
     *
     * An author may create folders in their own area: the tree is the author's own structure (any
     * depth, any file type), so a folder is as much theirs as a file is. The area itself is created on
     * first use, the same way a first save creates it.
     */
    #[NoAdminRequired]
    public function makeFolder(string $path = ''): DataResponse
    {
        if ($this->isAuthorScoped($path)) {
            return $this->authorCall(function () use ($path): DataResponse {
                $areas = $this->authorAreas();
                $uid = $this->currentUserId();
                $resolved = $areas->resolve($uid, $path);
                $deny = $this->denyReason($resolved, $uid, false);
                if ($deny !== null) {
                    return $this->authorDenied($deny, $path);
                }
                $areas->ensureArea($uid);
                $result = $this->files->mkdir($uid, $areas->area(), (string)$resolved['file']);

                return new DataResponse([
                    ...$result,
                    'path' => $resolved['target'],
                    'owner' => $uid,
                    'bridge' => $this->syncAuthorSources(),
                ]);
            });
        }
        [$denied, $site, $userId] = $this->siteContext('edit');
        if ($denied !== null) {
            return $denied;
        }
        try {
            $result = $this->files->mkdir($userId, (string)$site['nc_path'], $path);
        } catch (\Throwable $e) {
            return $this->translate($e) ?? throw $e;
        }
        return new DataResponse([...$result, 'bridge' => $this->notifyBridge()]);
    }

    /**
     * Rename / move: its own route because POST /file means "write file content", and a rename
     * there would blur what "already exists" refers to.
     *
     * For an author both ends are targets of their own area, which is what keeps a rename inside the
     * namespace the payload can address. Renaming a file the site serves is not a silent address
     * change: the new name starts as a draft (see `registerArticle()`), the old address stays in the
     * published list until a publish carries the move out, and the mirror only gets the new target
     * once it is approved - so the new address ships with a review, exactly like a new file does.
     *
     * The target's file name may carry a day for the site's own `_posts/**`, where Jekyll reads a
     * post's date from the name: the same "no later than today" rule the structured save applies to
     * the `date` field is applied here to the name (see `nameDayNotAfterToday()`). The check runs
     * before the rename, so a refused move leaves both ends exactly where they were.
     */
    #[NoAdminRequired]
    public function move(string $from = '', string $to = ''): DataResponse
    {
        // a move is an author-area move when either end names one: the mapper then refuses a target
        // of the site skeleton, instead of the site branch writing an author target into the source
        if ($this->isAuthorScoped($from) || $this->isAuthorScoped($to)) {
            return $this->authorCall(function () use ($from, $to): DataResponse {
                $areas = $this->authorAreas();
                $uid = $this->currentUserId();
                $source = $areas->resolve($uid, $from);
                $deny = $this->denyReason($source, $uid);
                if ($deny !== null) {
                    return $this->authorDenied($deny, $from);
                }
                $target = $areas->resolve($uid, $to);
                $deny = $this->denyReason($target, $uid, false);
                if ($deny !== null) {
                    return $this->authorDenied($deny, $to);
                }
                $this->nameDayNotAfterToday($target['target']);
                $result = $this->files->rename($uid, $areas->area(), $source['file'], $target['file']);
                $this->registerArticle($uid, $areas->area(), $target);

                return new DataResponse([
                    ...$result,
                    'from' => $source['target'],
                    'path' => $target['target'],
                    'owner' => $uid,
                    'bridge' => $this->syncAuthorSources(),
                ]);
            });
        }
        [$denied, $site, $userId] = $this->siteContext('edit');
        if ($denied !== null) {
            return $denied;
        }
        try {
            $this->nameDayNotAfterToday($to);
            $result = $this->files->rename($userId, (string)$site['nc_path'], $from, $to);
        } catch (\Throwable $e) {
            return $this->translate($e) ?? throw $e;
        }
        return new DataResponse([...$result, 'bridge' => $this->notifyBridge()]);
    }

    /**
     * Keep the article index current on an author's own save. A brand new author has to be known to
     * the database before the next configuration push asks who the authors are, otherwise their first
     * article would only reach the site after somebody happened to list the articles.
     *
     * @param array{ok:bool,code:string,uid:string,file:string,target:string} $resolved
     */
    private function registerArticle(string $uid, string $area, array $resolved): void
    {
        $target = (string)($resolved['target'] ?? '');
        // every file of an author area is an entry (an image exactly like a page); the site skeleton's
        // own files are not, so only a target that names an author is registered here
        if ($target === '' || $this->authorAreas()->uidOfTarget($target) === null) {
            return;
        }
        try {
            // registering also takes the review decision back: an edited article is a draft again, and
            // that reset is written to the review manifest (the app's truth) and synced into the cache
            $this->articleIndex->register($uid, $area, (string)$resolved['file'], $target);
        } catch (\Throwable) {
            // The file is already written at this point, so failing the save would tell the author that
            // something did not happen which did. What a failure here costs is bookkeeping: an article
            // that stays approved while its file changed, which the next save of that article (or the
            // next review action, which fails loudly when the manifest cannot be written) repairs.
        }
    }

    /**
     * Whether an endpoint's path is served by the author branch rather than by the site tree.
     *
     * The path's namespace decides, not the caller's identity: a target that names an author area
     * (`<author_root>/<uid>/…`) is that area's file for everybody, and a caller without the publish
     * grant addresses nothing else. That is what keeps the author view of the site's storage user -
     * their own `Pages/` folder, mirrored into `<author_root>/<uid>/` - out of the site source tree
     * they happen to own, and it is the same rule the two article endpoints decide by.
     *
     * The retired `_posts/<uid>/…` prefix is deliberately **not** part of it: `_posts/` is a folder of
     * the site itself, and an editor may keep posts in a subfolder of it. A caller without the
     * publish grant still has such a legacy target resolved (the mapper accepts it for every
     * endpoint); an editor, who reaches the whole site, means the site when naming that folder.
     */
    private function isAuthorScoped(string $path): bool
    {
        if (!$this->canEdit()) {
            return true;
        }
        $areas = $this->authorAreas();

        return $areas->uidOfTarget($path) !== null && !$areas->isLegacyTarget($path);
    }

    /**
     * An article target resolved inside the author area it names, with the refusal to answer when the
     * caller may not address it.
     *
     * An editor may address any author area, everybody else only their own, and the mapper is what
     * refuses a foreign target — so the uid handed to it is the caller's own unless the caller has the
     * edit grant and the path names somebody else. That uid is also the user whose files hold the
     * article: an editor's save of somebody else's article writes *that* author's file, the same way
     * the whole-file endpoint writes the site storage user's.
     *
     * @return array{deny:?DataResponse,ok:bool,code:string,uid:string,file:string,target:string}
     */
    private function resolveArticle(string $path): array
    {
        $areas = $this->authorAreas();
        $selfOnly = !$this->canTouchAuthorAreas();
        $named = $areas->uidOfTarget($path);
        $uid = $selfOnly ? $this->currentUserId() : ($named ?? $this->currentUserId());
        $resolved = $areas->resolve($uid, $path, $selfOnly);
        $deny = $this->denyReason($resolved, $uid);
        if ($deny !== null) {
            return ['deny' => $this->authorDenied($deny, $path), ...$resolved];
        }
        if (!$areas->isRenderable($resolved['file'])) {
            // this endpoint speaks about articles: any other file of the area (an image, a stylesheet)
            // is read and written whole through the file endpoints, never as fields
            return ['deny' => $this->authorDenied('BAD_REQUEST', $path), ...$resolved];
        }

        return ['deny' => null, ...$resolved];
    }

    /**
     * The write itself, shared by the two ownership worlds: read the document that is on disk, merge the
     * named changes into it, write it back under the base etag, answer with what is there now.
     *
     * The file is read here rather than trusted from the request, because the block on disk is the only
     * complete copy of the keys this app does not model. The optimistic lock stays where it was —
     * `FileService::write()` compares the base etag and throws before anything is written, so a stale
     * etag answers 409 and leaves the file untouched.
     *
     * @param array<string,mixed> $set    keys to write (a null value would remove, `update()`'s own rule)
     * @param list<string>        $remove keys to drop
     * @return array<string,mixed> the answer of a save, without the bridge result
     */
    private function saveArticle(
        string $uid,
        string $root,
        string $rel,
        string $target,
        array $set,
        array $remove,
        string $body,
        ?string $baseEtag,
    ): array {
        $current = $this->files->read($uid, $root, $rel);
        $document = FrontMatter::updateBody(
            FrontMatter::update((string)$current['content'], $set, $remove),
            $body,
        );
        $this->files->write($uid, $root, $rel, $document, $baseEtag);
        // read back instead of describing the write: the etag, the body and the fields in the answer are
        // then exactly what the next request will find, whatever `update()` made of the input
        $fresh = $this->files->read($uid, $root, $rel);

        return [...$this->articleAnswer($target, $fresh), 'modified' => (string)$set['modified_date']];
    }

    /**
     * The answer both article endpoints give: the body, the etag to send back on the next save, and the
     * front matter as fields. `path` is the canonical article target (with the one leading slash every
     * article path in this app carries).
     *
     * @param array<string,mixed> $file a FileService::read() result
     * @return array<string,mixed>
     */
    private function articleAnswer(string $target, array $file): array
    {
        $path = '/' . ltrim(str_replace('\\', '/', trim($target)), '/');
        $raw = (string)($file['content'] ?? '');
        $parsed = FrontMatter::parse($raw);

        return [
            'ok' => true,
            'path' => $path,
            'etag' => (string)($file['etag'] ?? ''),
            // the body without the blank line the fence needs: that blank line is punctuation of the
            // document, so a body read here and sent straight back leaves the file as it was
            'body' => FrontMatter::normalizeBody($parsed['body']),
            'meta' => $this->articleMeta($parsed['data'], $path),
            // the author the target names, '' for a post of the site skeleton: the same reading of a
            // target the rest of the app uses
            'owner' => $this->authorAreas()->uidOfTarget($path) ?? '',
        ];
    }

    /**
     * The front matter of one article as fields.
     *
     * `title` and `date` fall back the way Jekyll resolves them (the file name carries both), so the
     * fields show what the site shows and a field the user did not touch is never sent back; `excerpt`
     * is the stored value only, because an excerpt Jekyll would generate is not a key in the file and
     * writing one back would freeze it. `keys` lists what the block actually holds, in file order: it is
     * what tells the panel which keys a save is preserving, without the client parsing anything.
     *
     * @param array<string,mixed> $data the parsed front matter
     * @param string              $path the article path the file name falls back to
     * @return array<string,mixed>
     */
    private function articleMeta(array $data, string $path): array
    {
        $stamp = FrontMatter::date($data, $path);

        $known = [
            'title' => FrontMatter::title($data, $path),
            'tags' => FrontMatter::tags($data),
            // the categories of a page decide its address in the built site (one copy per usable
            // category), so the panel edits them like any other field of the block
            'categories' => FrontMatter::categories($data),
            // a day, not the raw scalar: the field is a day, and a full timestamp handed back as the
            // field's own value would be rewritten (and shortened) by a save that never named the date
            'date' => $stamp > 0 ? date('Y-m-d', $stamp) : '',
            'excerpt' => self::fieldText($data['excerpt'] ?? null),
            'modified_date' => self::fieldText($data['modified_date'] ?? null),
            'author' => self::fieldText($data['author'] ?? null),
            'permalink' => self::fieldText($data['permalink'] ?? null),
            /*
             * The address the built site serves this file at, computed rather than read off the front
             * matter: most pages carry no `permalink` of their own, so the field above is empty for
             * them and a preview that took it for the address would claim the page has none. For an
             * author's file the address is the one the site template's author mirror produces; for a
             * file of the site's own skeleton it is the site's permalink pattern.
             */
            'url' => $this->pageUrl($path, $data),
            'keys' => array_map(static fn (int|string $key): string => (string)$key, array_keys($data)),
        ];
        // Everything else the block holds travels with the answer: the panel shows those keys in its
        // read-only table and the metadata dialog edits them, and a save has to be able to name one.
        foreach ($data as $key => $value) {
            $key = (string)$key;
            if (!array_key_exists($key, $known)) {
                $known[$key] = self::metaValue($value);
            }
        }

        return $known;
    }

    /**
     * A front-matter value as the panel may receive it: a scalar stays one (a number and a boolean keep
     * their type, so the dialog can offer the right editor), a list becomes a list of strings, and
     * anything the parser could not model reads as '' rather than as a broken JSON shape.
     */
    private static function metaValue(mixed $value): mixed
    {
        if (is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
            return $value;
        }
        if (is_array($value)) {
            return array_values(array_filter(
                array_map(static fn (mixed $item): string => is_scalar($item) ? (string)$item : '', $value),
                static fn (string $item): bool => $item !== '',
            ));
        }

        return '';
    }

    /**
     * The `set`/`remove` pair `FrontMatter::update()` takes, out of the request's `meta` object.
     *
     * Three rules, in this order:
     *  - read-only keys (`permalink`, `keys`, `modified_date`, `author`) are accepted and ignored;
     *  - the keys the app owns are validated as fields (`title`/`date`/`tags`/`categories`);
     *  - **any other key** matching `^[a-z0-9_]+$` (at most 64 characters) is written as the client
     *    sends it, as long as its value is a string, a number, a boolean or a list of strings. New keys
     *    are appended at the end of the block and an existing one keeps its line and its order
     *    (`FrontMatter::update()` guarantees both), so a save never reorders the author's block.
     *
     * An empty value (`''`, `[]`, null) removes the key. `date` has a rule of its own: an ISO day no
     * later than today, so a scheduled post cannot be created through the fields panel by accident.
     *
     * @return array{0:array<string,mixed>,1:list<string>} the values to write and the keys to drop
     * @throws \InvalidArgumentException when the object cannot be read as fields
     */
    private function metaEdit(): array
    {
        $meta = $this->request->getParam('meta', null);
        if ($meta === null) {
            return [[], []];
        }
        if (!is_array($meta)) {
            throw new \InvalidArgumentException($this->l10n->t('The meta field must be an object'));
        }

        $set = [];
        $remove = [];
        foreach ($meta as $key => $value) {
            $key = (string)$key;
            if (in_array($key, self::META_READ_ONLY, true)) {
                continue;
            }
            // a client model that carries the field but has nothing in it sends null: "not set" is what
            // the contract spells as an absent field, and removing the key is spelled with ''
            if ($value === null) {
                continue;
            }
            if (in_array($key, self::META_OWNED, true)) {
                [$set, $remove] = $this->metaOwned($key, $value, $set, $remove);
                continue;
            }
            [$set, $remove] = $this->metaExtra($key, $value, $set, $remove);
        }

        return [$set, $remove];
    }

    /**
     * One app-owned field: `tags` and `categories` are lists (or the one line a human typed), `date`
     * is a day, and `title` is text. `''`/`[]` removes the key.
     *
     * @param array<string,mixed> $set
     * @param list<string> $remove
     * @return array{0:array<string,mixed>,1:list<string>}
     * @throws \InvalidArgumentException
     */
    private function metaOwned(string $key, mixed $value, array $set, array $remove): array
    {
        if ($key === 'tags') {
            $tags = $this->tagList($value);
            if ($tags === []) {
                $remove[] = $key;
            } else {
                // one line, spaces between the tags: what Jekyll reads, and what FrontMatter::tags()
                // reads back. toYaml() quotes the whole value when a tag needs it (a number, a quote),
                // which still reads back as the same tags
                $set[$key] = implode(' ', $tags);
            }

            return [$set, $remove];
        }
        if ($key === 'categories') {
            $categories = $this->categoryList($value);
            if ($categories === []) {
                $remove[] = $key;
            } else {
                // an inline list: one category is one site folder, and the block stays one line
                $set[$key] = $categories;
            }

            return [$set, $remove];
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException($this->l10n->t('The %s field must be text', [$key]));
        }
        $text = trim($value);
        if ($text === '') {
            $remove[] = $key;

            return [$set, $remove];
        }
        $set[$key] = $key === 'date' ? $this->dayNotAfterToday($text) : $text;

        return [$set, $remove];
    }

    /**
     * The categories of a page: a list of names, or the one line a human typed (commas or spaces).
     *
     * A category becomes a folder at the site root, so each name is a single path segment; duplicates
     * collapse (the site would make the same copy twice) and at most MAX_CATEGORIES survive. A name
     * only takes effect when the site root holds a page of that name - that is the site's own rule,
     * and the panel's hint says so.
     *
     * @return list<string>
     * @throws \InvalidArgumentException
     */
    private function categoryList(mixed $value): array
    {
        if (is_string($value)) {
            $items = preg_split('/[\s,]+/', trim($value)) ?: [];
        } elseif (is_array($value) && array_is_list($value)) {
            $items = $value;
        } else {
            throw new \InvalidArgumentException($this->l10n->t('The categories field must be a list of names'));
        }
        $out = [];
        foreach ($items as $item) {
            if (!is_string($item)) {
                throw new \InvalidArgumentException($this->l10n->t('The categories field must be a list of names'));
            }
            $name = trim($item);
            if ($name === '') {
                continue;
            }
            if (preg_match(self::CATEGORY_NAME, $name) !== 1) {
                throw new \InvalidArgumentException($this->l10n->t(
                    'The category “%s” is not allowed: use lower-case letters, digits, underscore or hyphen.',
                    [$name],
                ));
            }
            if (in_array($name, $out, true)) {
                continue;
            }
            if (count($out) >= self::MAX_CATEGORIES) {
                throw new \InvalidArgumentException($this->l10n->t(
                    'A page may carry at most %d categories.',
                    [self::MAX_CATEGORIES],
                ));
            }
            $out[] = $name;
        }

        return $out;
    }

    /**
     * One key the app does not own: its name has to be a safe front-matter key and its value a scalar
     * or a list of strings. An empty value removes it.
     *
     * @param array<string,mixed> $set
     * @param list<string> $remove
     * @return array{0:array<string,mixed>,1:list<string>}
     * @throws \InvalidArgumentException
     */
    private function metaExtra(string $key, mixed $value, array $set, array $remove): array
    {
        if (strlen($key) > self::META_EXTRA_KEY_MAX || preg_match(self::META_EXTRA_KEY, $key) !== 1) {
            throw new \InvalidArgumentException($this->l10n->t(
                'The metadata key “%s” is not allowed: use lower-case letters, digits and underscore, at most 64 characters.',
                [$key],
            ));
        }
        if (is_string($value)) {
            $text = trim($value);
            if ($text === '') {
                $remove[] = $key;
            } else {
                $set[$key] = $text;
            }

            return [$set, $remove];
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            // a number or a boolean keeps its type: writing "false" would read back as a string
            $set[$key] = $value;

            return [$set, $remove];
        }
        if (is_array($value)) {
            if (!array_is_list($value)) {
                throw new \InvalidArgumentException($this->l10n->t(
                    'The value of “%s” must be text, a number, a boolean or a list of strings.',
                    [$key],
                ));
            }
            $items = [];
            foreach ($value as $item) {
                if (!is_string($item)) {
                    throw new \InvalidArgumentException($this->l10n->t(
                        'The value of “%s” must be text, a number, a boolean or a list of strings.',
                        [$key],
                    ));
                }
                $item = trim($item);
                if ($item !== '') {
                    $items[] = $item;
                }
            }
            if ($items === []) {
                $remove[] = $key;
            } else {
                $set[$key] = $items;
            }

            return [$set, $remove];
        }

        throw new \InvalidArgumentException($this->l10n->t(
            'The value of “%s” must be text, a number, a boolean or a list of strings.',
            [$key],
        ));
    }

    /**
     * A day the structured editor may write: an ISO day (`YYYY-MM-DD`) and never after today.
     *
     * `date()` is the instance's own clock (Nextcloud runs PHP in UTC), so "today" is the instance's
     * day; a client in another timezone may be a few hours off, which is accepted, while tomorrow is
     * not. The value is refused rather than shortened: a `date` a panel cannot show as a day is a
     * client bug, and writing part of it would hide that.
     *
     * @throws \InvalidArgumentException when the value is not a real day, or is in the future
     */
    private function dayNotAfterToday(string $value): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1
            || !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])) {
            throw new \InvalidArgumentException(
                $this->l10n->t('The date must be a day in the form YYYY-MM-DD.'),
            );
        }
        if ($this->isAfterToday($value)) {
            throw new \InvalidArgumentException(
                $this->l10n->t('The date may not be in the future.'),
            );
        }

        return $value;
    }

    /**
     * Is that ISO day later than the instance's own day? The one comparison of the two, shared by the
     * `date` field (`dayNotAfterToday()`) and the post file name (`nameDayNotAfterToday()`), so both
     * entry points read the clock the same way.
     */
    private function isAfterToday(string $day): bool
    {
        return strcmp($day, date('Y-m-d')) > 0;
    }

    /**
     * A date in a *site* post's file name.
     *
     * A Jekyll post's date is part of its name (`_posts/**`, `YYYY-MM-DD-slug.md`) and the site reads
     * it from there, so a name is the second way a date enters such a file: creating a file and
     * renaming one (a move is the rename) both change it. A name that schedules a post the site does
     * not show is the same accident the `date` field is refused for, so it is refused here as well,
     * with the instance's own day as the boundary (`isAfterToday()`, PHP runs in UTC). Today and every
     * earlier day are accepted, which is what keeps a rename that only changes the slug working.
     *
     * Only the site skeleton is held to this rule. An author's file is a template page, and Jekyll
     * takes a page's date from its front matter - never from its name - so a page may be called
     * `2026-01-01-notes.md` without any of this meaning anything.
     *
     * @throws \InvalidArgumentException when the name carries a day later than today
     */
    private function nameDayNotAfterToday(string $path): void
    {
        $clean = ltrim(str_replace('\\', '/', trim($path)), '/');
        // only the site's own `_posts/**` carries a date in its name: an author's file is a template
        // page, and its address comes from its title, never from a day in its file name
        if (!str_starts_with($clean, AuthorAreaService::LEGACY_PREFIX . '/')) {
            return;
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', basename($clean), $day) !== 1) {
            return;
        }
        if ($this->isAfterToday($day[1])) {
            throw new \InvalidArgumentException(
                $this->l10n->t('The file name may not carry a future date.'),
            );
        }
    }

    /**
     * A tag field as a list of tags: the client sends a list, a form may send the one line a human typed,
     * and both mean the same thing to Jekyll. The reading rule is the app's own (`FrontMatter` splits on
     * whitespace and commas, trims and deduplicates), so a save cannot write a tag list the app would
     * read back differently.
     *
     * @return list<string>
     * @throws \InvalidArgumentException when the value is neither a list of words nor such a line
     */
    private function tagList(mixed $value): array
    {
        if (is_string($value)) {
            return FrontMatter::stringList($value);
        }
        if (!is_array($value)) {
            throw new \InvalidArgumentException($this->l10n->t('The tags field must be a list of words'));
        }
        $words = [];
        foreach ($value as $item) {
            if ($item !== null && !is_scalar($item)) {
                throw new \InvalidArgumentException($this->l10n->t('The tags field must be a list of words'));
            }
            $words[] = (string)$item;
        }

        return FrontMatter::stringList($words);
    }

    /**
     * One request value that has to be text, or null while it is not there at all. A JSON body can put a
     * list or an object where the contract has a string, and PHP would write "Array" into the file rather
     * than refuse it.
     *
     * @throws \InvalidArgumentException when the value is present but not text
     */
    private function textField(string $name): ?string
    {
        $value = $this->request->getParam($name, null);
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException($this->l10n->t('The %s field must be text', [$name]));
        }

        return $value;
    }

    /**
     * A site-relative path as an article target, or null when it names something else: an author's
     * renderable file (`<author_root>/<uid>/…`) or a post of the site's own `_posts/`. That is exactly
     * the rule the index scans with, so an image, a layout or `_config.yml` can never be pulled into
     * the fields panel.
     */
    private function articleTarget(string $path): ?string
    {
        $clean = ltrim(str_replace('\\', '/', trim($path)), '/');
        $areas = $this->authorAreas();
        if ($areas->uidOfTarget($clean) !== null) {
            return $areas->isRenderable($clean) ? '/' . $clean : null;
        }
        if (!str_starts_with($clean, '_posts/') || !$areas->isRenderable($clean)) {
            return null;
        }

        return '/' . $clean;
    }

    /** A front-matter value as a field value: its text when it is a scalar, '' when it cannot be one. */
    private static function fieldText(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }

    /**
     * One node of the author's own tree as the content page's column needs it: its site target
     * (folders included, because every operation of the tree is addressed by one), what the node is,
     * and the review state its badge shows.
     *
     * @param array<string,mixed>      $node
     * @param array<string,mixed>      $manifest the review manifest, read once for the whole tree
     * @return array<string,mixed>
     */
    private function withTarget(string $uid, array $node, array $manifest): array
    {
        $areas = $this->authorAreas();
        $rel = (string)($node['path'] ?? '');
        $target = $rel === '' ? '' : $areas->targetFor($uid, $rel);
        $node['target'] = $target === '' ? '' : '/' . $target;
        $isDir = ($node['type'] ?? '') === 'dir';
        // the three kinds the column draws differently: a folder, a page the site renders, and every
        // other file (an image, a stylesheet) which is an entry of the review just the same
        $node['kind'] = match (true) {
            $isDir => 'dir',
            $areas->isRenderable($rel) => 'page',
            default => 'resource',
        };
        $node['state'] = '';
        $node['intent'] = '';
        $node['live'] = false;
        if (!$isDir && $node['target'] !== '') {
            // the author of the entry: the front end needs it to tell a reviewer's own request from
            // somebody else's (nobody decides about their own request)
            $node['owner'] = $uid;
            $node['state'] = ReviewStore::stateOf($manifest, (string)$node['target']);
            // the direction of a pending request, for the row that offers "withdraw"
            $node['intent'] = ReviewStore::intentOf($manifest, (string)$node['target']);
            // `live` is the site's own answer (the published list), never the visibility state: an
            // entry approved for the site that no commit has carried yet is not served
            $node['live'] = ReviewStore::isPublished($manifest, (string)$node['target']);
            // the request's other facts travel with the row: "requested on <date>" needs the moment,
            // and a rejected request shows whose decision and why
            $entry = ReviewStore::entryOf($manifest, (string)$node['target']);
            $node['submittedAt'] = (int)($entry['submittedAt'] ?? 0);
            $node['decidedBy'] = (string)($entry['decidedBy'] ?? '');
            $node['reviewNote'] = (string)($entry['note'] ?? '');
        }

        return $node;
    }

    /**
     * Refuses a target the caller may not address, or null when it is theirs. `$mustExist` is false
     * for the target of a move, which the app creates.
     *
     * @param array{ok:bool,code:string,uid:string,file:string,target:string} $resolved
     */
    private function denyReason(array $resolved, string $uid, bool $mustExist = true): ?string
    {
        if (!$resolved['ok']) {
            return $resolved['code'] === 'foreign' ? 'FORBIDDEN' : 'BAD_REQUEST';
        }
        if ($resolved['uid'] !== $uid) {
            return 'FORBIDDEN';
        }
        if ($mustExist && !$this->authorNodeExists($uid, $this->authorAreas()->area(), $resolved['file'])) {
            return 'NOT_FOUND';
        }

        return null;
    }

    private function authorDenied(string $reason, string $path): DataResponse
    {
        return match ($reason) {
            'FORBIDDEN' => new DataResponse(
                ['error' => 'FORBIDDEN', 'message' => $this->l10n->t('You may only change your own files: %s', [$path])],
                403,
            ),
            'NOT_FOUND' => new DataResponse(
                ['error' => 'NOT_FOUND', 'message' => $this->l10n->t('File does not exist: %s', [$path])],
                404,
            ),
            default => new DataResponse(
                ['error' => 'BAD_REQUEST', 'message' => $this->l10n->t('Not a file of your author area: %s', [$path])],
                400,
            ),
        };
    }

    /** Whether one file of the author area is there; a folder of that name does not count as one. */
    private function authorFileExists(string $uid, string $area, string $rel): bool
    {
        try {
            $folder = $this->files->existingFolder($uid, $area);
            if ($folder === null) {
                return false;
            }

            return $folder->get($rel) instanceof File;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Whether a path inside an author area is there at all - a file or a folder. A move is the reason
     * this exists: renaming a folder is a move like any other, and a file-only check would answer 404
     * for it.
     */
    private function authorNodeExists(string $uid, string $area, string $rel): bool
    {
        try {
            $folder = $this->files->existingFolder($uid, $area);
            if ($folder === null) {
                return false;
            }
            $folder->get($rel);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Whether a path inside an author area names a folder (the one thing a delete has to tell apart). */
    private function authorNodeIsFolder(string $uid, string $area, string $rel): bool
    {
        try {
            $folder = $this->files->existingFolder($uid, $area);
            if ($folder === null) {
                return false;
            }

            return $folder->get($rel) instanceof Folder;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Forgets the bookkeeping of one deleted file: its manifest entry (and the cache row), so nothing
     * is left to request. The `published` list is deliberately kept - a path the site still serves is
     * what the next publish stages the removal from.
     *
     * A failure is reported in the answer instead of thrown: the file is already gone at this point,
     * and a stale manifest entry is repaired by the next scan, while an exception would tell the
     * author that the deletion did not happen.
     *
     * @param string $target the file as a site target
     * @return array{updated:bool,message?:string}
     */
    private function forgetArticleEntry(string $target): array
    {
        $key = ltrim(trim(str_replace('\\', '/', $target)), '/');
        if ($key === '') {
            return ['updated' => true];
        }
        try {
            $this->review->forgetArticle([$key]);

            return ['updated' => true];
        } catch (\Throwable $e) {
            return ['updated' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Forgets the review bookkeeping of everything a folder held: the files are gone with it, so
     * nothing about them is left to decide.
     *
     * A failure is reported in the answer instead of thrown - the folder is already deleted at this
     * point, and a stale manifest entry is repaired by the next scan, while an exception would tell
     * the author that the deletion did not happen.
     *
     * @param string $target the folder as a site target
     * @return array{updated:bool,message?:string}
     */
    private function forgetAuthorEntries(string $target): array
    {
        $key = ltrim(trim(str_replace('\\', '/', $target)), '/');
        if ($key === '') {
            return ['updated' => true];
        }
        try {
            $manifest = $this->review->read();
            $keys = [];
            foreach (array_keys($manifest['entries'] + $manifest['published']) as $path) {
                if (str_starts_with((string)$path, $key . '/')) {
                    $keys[] = (string)$path;
                }
            }
            if ($keys !== []) {
                $this->review->forgetArticle($keys);
                $this->review->forget($keys);
            }

            return ['updated' => true];
        } catch (\Throwable $e) {
            return ['updated' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * The address the built site serves one file at.
     *
     * An author's file is a page of the site template, so its address is what the template's author
     * mirror produces (see {@see PageUrlService}); a file of the site's own skeleton keeps the site's
     * permalink pattern. The address is computed here and not read off the front matter, because a
     * page that carries no `permalink` still has one.
     *
     * @param array<string,mixed> $data the parsed front matter
     */
    private function pageUrl(string $path, array $data): string
    {
        $clean = ltrim(trim(str_replace('\\', '/', $path)), '/');
        $areas = $this->authorAreas();
        $uid = $areas->uidOfTarget($clean);
        if ($uid === null) {
            return FrontMatter::url('', $data, $clean);
        }
        $site = $this->registry->getSite();
        $owner = trim((string)($site['nc_user'] ?? ''));
        $root = trim((string)($site['nc_path'] ?? ''));
        $rel = $areas->areaRelative($uid, $clean);
        if ($owner === '' || $root === '' || $rel === '') {
            // no site to read the template configuration from: saying "no address" is honest, and the
            // panel draws the preview as "not on the site yet"
            return '';
        }

        return $this->pageUrls->forAuthorPage($owner, $root, $uid, $rel, $data);
    }

    /**
     * The site, without asking for a capability: the author branch of every endpoint needs the site
     * to exist, but must not run the edit check first. Returns the response to send when the site is
     * missing, otherwise null.
     */
    private function requireSite(): ?DataResponse
    {
        if ($this->registry->getSite() === null) {
            return new DataResponse(
                ['error' => 'NOT_CONFIGURED', 'message' => $this->l10n->t('No site is configured yet')],
                412,
            );
        }

        return null;
    }

    /**
     * Runs the body of an author-scoped endpoint, mapping the exceptions it may raise to the same
     * structured errors the rest of the app answers with.
     *
     * @param callable():DataResponse $body
     */
    private function authorCall(callable $body): DataResponse
    {
        try {
            return $body();
        } catch (ConflictException $e) {
            return new DataResponse($e->toArray(), 409);
        } catch (\Throwable $e) {
            return $this->translate($e) ?? throw $e;
        }
    }

    /**
     * Notifies the bridge of a change; a failed notification is not a failed save, the file is
     * already on disk, so the error is handed back to the UI instead.
     *
     * @return array<string,mixed>
     */
    private function notifyBridge(): array
    {
        try {
            return ['notified' => true, 'result' => $this->bridge->notify()];
        } catch (\Throwable $e) {
            return [
                'notified' => false,
                'error' => $e instanceof BridgeException ? $e->getBridgeCode() : 'ERROR',
                'message' => $e->getMessage(),
            ];
        }
    }
}
