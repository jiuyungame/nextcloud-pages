<?php

declare(strict_types=1);

namespace OCA\Pages\Controller;

use OCA\Pages\AppInfo\Application;
use OCA\Pages\Service\ArticleIndexService;
use OCA\Pages\Service\AuthorDeltaService;
use OCA\Pages\Service\BridgeClient;
use OCA\Pages\Service\BridgeException;
use OCA\Pages\Service\ConflictException;
use OCA\Pages\Service\FileService;
use OCA\Pages\Service\FrontMatter;
use OCA\Pages\Service\OrphanTransfer;
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
use OCP\Server;

/**
 * File browsing and editing of an **author area**: a site target (`<author_root>/<uid>/…`), which every
 * author addresses - the site's storage user and an administrator exactly like anybody else.
 * Everything goes through FileService (Nextcloud Files API), so version history, trash and desktop sync
 * keep working; a successful save notifies the bridge to rebuild the preview.
 *
 * The site's own source folder is **not** edited here any more: its writers are the Files app /
 * WebDAV, the tarball Import and the git Pull, and all of them replace it completely (see
 * SiteController::importSource() and SiteController::pull()). A target that names another author is
 * answered 403 by the mapper, so no endpoint here reaches a foreign folder.
 *
 * An author's files are pages of the site's template, never posts: the one rule is where a file is
 * rendered (see PageUrlService), not what it is.
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
     * character, because a Chinese category is as valid a folder name as a Chinese slug is. 
     */
    private const CATEGORY_NAME = '/^[a-z0-9_\-\/\x{80}-\x{10FFFF}]+$/u';

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

    /**
     * What a delete does with a page the site serves (`mode` of DELETE /api/site/file):
     *
     *  - `''`               plain delete: the file and its manifest entry go; a page the site does
     *                       not serve has no copy to take down or hand over, so nothing else
     *                       follows.
     *                       On a page the site **serves** this mode cannot be honoured - the page would
     *                       stay online with nobody asked to take it down, and with the entry gone
     *                       nothing could be decided about it any more - so it degrades to
     *                       `request-offline` and the answer says `mode: 'request-offline'`,
     *                       `reason: 'published'`.
     *  - `request-offline`  the file goes, the entry stays: a `downRequest` with `sourceDeleted: true`
     *                       is recorded, so a reviewer decides instead of the author (see
     *                       ArticleReviewService::decide()).
     *  - `keep`             the file goes and the published copy is handed over to the orphan owner
     *                       right away (see OrphanTransfer).
     *
     * A file the site does not serve has nothing to take down or hand over: both modes degrade to a
     * plain delete, and the answer says so (`reason: 'not-published'`).
     *
     * A **folder** has no single mode, because what has to be taken down is each of the pages inside
     * it: every file of the folder the site still serves keeps its entry and gets a take-down request
     * ({@see ReviewStore::requestTakeDownForDeletedSources()}, one write for the whole subtree), every
     * other entry goes with the folder, and the answer reports the two counts with `mode_applied: false`
     * and `reason: 'folder'`.
     */
    public const DELETE_MODE_OFFLINE = 'request-offline';
    public const DELETE_MODE_KEEP = 'keep';
    /** @var list<string> */
    public const DELETE_MODES = [self::DELETE_MODE_OFFLINE, self::DELETE_MODE_KEEP];


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
        private OrphanTransfer $orphanTransfer,
    ) {
        parent::__construct(Application::APP_ID, $request, $userSession, $groupManager, $registry, $files, $bridge, $l10n);
    }

    /**
     * The whole controller is authoring territory: the tree, the whole-file calls (read, write, create,
     * delete, move, makeFolder) and the two article-editor calls all run the authoring gate
     * (`author_groups`, see BaseController::requireAuthor()) before they touch a file, so a signed-in
     * user outside the grant can neither list the tree nor reach the files behind it. The framework's
     * admin gate is therefore lifted at the route level; the capability is checked here.
     */
    #[NoAdminRequired]
    public function tree(): DataResponse
    {
        // The one tree of this app: the caller's own author area, for every caller - the site's
        // storage user and an administrator included. The `scope` parameter left with the source
        // page: a client that still sends it gets the same author tree, because that is the only
        // tree this endpoint can describe.
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
        if (($author = $this->requireAuthor()) !== null) {
            return $author;
        }
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

        return $this->notAnAuthorPath($path);
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
        if (($author = $this->requireAuthor()) !== null) {
            return $author;
        }
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

        return $this->notAnAuthorPath($path);
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
        if (($author = $this->requireAuthor()) !== null) {
            return $author;
        }
        try {
            $path = trim($this->textField('path') ?? '');
        } catch (\InvalidArgumentException $e) {
            return $this->translate($e) ?? throw $e;
        }
        if ($path === '') {
            return new DataResponse(['error' => 'BAD_REQUEST', 'message' => $this->l10n->t('A path is required')], 400);
        }

        // The path decides where the article is, the capability decides how far the caller reaches: a
        // path that names an author area (`<author_root>/<uid>/…`) is an article of that area, and a
        // path that names nobody is a file of the site skeleton. An author is refused a skeleton path
        // by the mapper (403, like the file endpoints); an editor reaches both.
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

        return $this->notAnArticlePath($path);
    }

    /**
     * Saves an article: the body as sent plus the fields the client names.
     *
     *   {"path": "/<author_root>/<uid>/2026-05-01-hello.md", "body": "…", "baseEtag": "…",
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
        if (($author = $this->requireAuthor()) !== null) {
            return $author;
        }
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
                // article (a brand new author is only an author once the database says so). The
                // visibility state is left alone: an edit is not a review decision.
                $this->registerArticle($article['uid'], $areas->area(), $article);

                // the file change travels as a file-event delta; the call below refreshes the site
                // configuration and asks the bridge to rebuild - whoever saved it
                return new DataResponse([...$answer, 'bridge' => $this->syncAuthorSources()]);
            });
        }

        return $this->notAnArticlePath($path);
    }

    /**
     * Creates a file. Creation is the other way a name enters an article, so it is held to the same
     * rule a move is (`nameDayNotAfterToday()`): the two entry points must not be able to disagree
     * about a file the app then refuses to save. That rule is about an author's target, whose file
     * name may carry the article's date.
     */
    #[NoAdminRequired]
    public function create(string $path = '', string $content = ''): DataResponse
    {
        if (($author = $this->requireAuthor()) !== null) {
            return $author;
        }
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

        return $this->notAnAuthorPath($path);
    }

    /**
     * Deletes a file or a folder through Nextcloud's own delete, so the trash keeps a copy.
     *
     * Deleting a file is direct - there is no reviewed deletion. What follows the delete depends on
     * `mode`, and on whether the site is serving the file at all:
     *
     *  - `''` (default) plain delete. A pending request about the target is withdrawn
     *    ({@see AuthorDeltaService::withdrawPending()}), the manifest entry goes with the file
     *    ({@see self::forgetArticleEntry()}), and `online/<target>` keeps the page where it is, which
     *    is how the next publish learns to take the page off the site. A folder is different - see
     *    below.
     *  - `request-offline` the file goes but the entry stays, with a fresh `downRequest` carrying
     *    `sourceDeleted: true`: the author deleted what they asked to take down, so the reviewer's
     *    refusal is no longer "keep the page up" - it transfers the published copy to the orphan owner
     *    ({@see ArticleReviewService::decide()}).
     *  - `keep` the file goes and the published copy is handed over immediately
     *    ({@see OrphanTransfer}), so the page survives as the site's own file and no request is filed.
     *
     * Both non-empty modes need a page the site serves (`online/<target>`): a file without one has
     * nothing to take down or hand over, so the mode is ignored and the answer says so
     * (`mode_applied: false` with the reason). The delete itself always happens.
     *
     * A **folder** is settled file by file as far as the site is concerned: every file inside it that
     * the site still serves keeps its entry and gets a `downRequest` with `sourceDeleted: true`
     * ({@see self::requestTakeDownOfDeleted()}), because a reviewer's take-down is the only operation
     * that clears `online/<target>` - the file event's `remove` delta drops the preview copy only. The
     * entries the site does not serve go with the folder, and the answer names the two counts
     * (`offline_requests`, `forgotten`), so a client can say "N pages wait to be taken down". A folder
     * has nothing to hand over and no single mode: the `mode` is reported as not applied, whatever the
     * caller sent.
     *
     * A plain delete of a file the site serves is the one combination that must not be carried out as
     * asked: with the file gone and no request filed the page would stay online with no entry left to
     * decide about (a reviewer's take-down answers 404 for it), i.e. permanently. It degrades to
     * `request-offline` - the safe direction, which leaves the reviewer in charge - and the answer says
     * `mode: 'request-offline'`, `mode_applied: true`, `reason: 'published'`.
     */
    #[NoAdminRequired]
    public function delete(string $path = '', string $mode = ''): DataResponse
    {
        if (($author = $this->requireAuthor()) !== null) {
            return $author;
        }
        $mode = trim($mode);
        if ($mode !== '' && !in_array($mode, self::DELETE_MODES, true)) {
            return new DataResponse(
                ['error' => 'BAD_REQUEST', 'message' => $this->l10n->t('Unknown delete mode: %s', [$mode])],
                400,
            );
        }
        if ($this->isAuthorScoped($path)) {
            return $this->authorCall(function () use ($path, $mode): DataResponse {
                $areas = $this->authorAreas();
                $uid = $this->currentUserId();
                $resolved = $areas->resolve($uid, $path);
                $deny = $this->denyReason($resolved, $uid);
                if ($deny !== null) {
                    return $this->authorDenied($deny, $path);
                }
                $target = (string)$resolved['target'];
                // Every following decision about the site's copies comes from the content
                // directories: whether the page is served (the mode) and whether a candidate still
                // waits. The Files API fallback cannot prove either, and the file is deleted below -
                // refusing here leaves everything as it was, so the author can retry.
                $this->review->requireTrustedTrees();
                $isFolder = $this->authorNodeIsFolder($uid, $areas->area(), (string)$resolved['file']);
                // the two facts the mode needs, read before the file is gone (the entry is the answer
                // to "may this be taken down or handed over at all")
                $published = !$isFolder && $this->review->isLive($target);
                // why the answer's mode is not the one the caller asked for, '' while it is
                $reason = null;
                if ($mode === '' && $published) {
                    // no mode, but the site serves the page: the plain delete would drop the entry and
                    // leave the page online with nobody asked to take it down, so it becomes the
                    // request that leaves the decision to a reviewer
                    $mode = self::DELETE_MODE_OFFLINE;
                    $reason = 'published';
                }

                $this->files->delete($uid, $areas->area(), (string)$resolved['file']);
                // the file is gone: whatever was waiting for a decision about it goes too, and the
                // frozen review copy (if any) is discarded through the bridge. A mode that keeps the
                // entry writes its own down request below, after this cleared the old one.
                $this->authorDeltas()->withdrawPending($uid, $target, $isFolder);

                $answer = [
                    'path' => $target,
                    'owner' => $uid,
                    'deleted' => true,
                    // the mode that was actually carried out, and whether it was the one asked for: a
                    // client can report "the file was deleted, the page stays / was handed over / is
                    // gone"
                    'mode' => $mode,
                    'mode_applied' => false,
                ];

                if ($mode === '' || !$published) {
                    if ($isFolder) {
                        // one write for the whole subtree: what the site still serves asks to be taken
                        // down, everything else goes with the folder
                        $bookkeeping = $this->requestTakeDownOfDeleted($target);
                        $answer['folder'] = true;
                        $answer['offline_requests'] = $bookkeeping['offline_requests'];
                        $answer['forgotten'] = $bookkeeping['forgotten'];
                        $answer['manifest'] = $bookkeeping['manifest'];
                        $answer['reason'] = $mode === '' ? null : 'folder';
                        // a candidate that could not be withdrawn keeps its entry and gets no
                        // request; the caller may retry the delete path for it
                        $answer['failed'] = $bookkeeping['failed'];
                        if ($bookkeeping['failed'] !== []) {
                            $answer['message'] = $this->l10n->t(
                                'The waiting candidate of %d file(s) could not be withdrawn; those pages were left as they were. Please try again.',
                                [count($bookkeeping['failed'])],
                            );
                        }

                        return new DataResponse([...
                            $answer,
                            'transferred' => false,
                            // the pages of the folder now wait for a decision, even though no mode of
                            // this request could be applied
                            'requestOffline' => $bookkeeping['offline_requests'] > 0,
                            'bridge' => $this->syncAuthorSources([$target]),
                        ]);
                    }
                    // the plain path, and the answer of the two modes for something the site does not
                    // serve: the entry goes with the file - unless a candidate is still waiting for
                    // it, because then the entry is the only landing point a later decision has
                    $answer['manifest'] = $this->forgetArticleEntry($target);
                    if (($answer['manifest']['pending'] ?? false) === true) {
                        $answer['mode_applied'] = false;
                        $answer['failed'] = [$target];
                        $answer['reason'] = 'candidate-stuck';
                        $answer['message'] = $this->l10n->t(
                            'The waiting candidate could not be withdrawn; the entry was kept and no take-off request was recorded. Please try again.',
                        );
                        $answer['manifest'] = ['updated' => false];

                        return new DataResponse([...
                            $answer,
                            'transferred' => false,
                            'requestOffline' => false,
                            'bridge' => $this->syncAuthorSources([$target]),
                        ]);
                    }
                    $answer['reason'] = $mode === '' ? null : 'not-published';

                    return new DataResponse([...
                        $answer,
                        'transferred' => false,
                        'requestOffline' => false,
                        'bridge' => $this->syncAuthorSources([$target]),
                    ]);
                }

                if ($mode === self::DELETE_MODE_OFFLINE) {
                    // the entry stays, so the page keeps being served from `online/` until a reviewer
                    // decides - and the request says the source is gone. A refusal now hands the
                    // published copy over instead of keeping an unmaintainable page online.
                    //
                    // It is only recorded while nothing waits in `pending/`: a candidate the delete
                    // path could not withdraw and a take-off request together would be a state no
                    // decision can answer. The file is already deleted - that fact is not rolled back -
                    // but nothing is recorded and the caller is told to retry.
                    if ($this->review->isPending($target)) {
                        $answer['mode_applied'] = false;
                        $answer['failed'] = [$target];
                        $answer['reason'] = 'candidate-stuck';
                        $answer['message'] = $this->l10n->t(
                            'The waiting candidate could not be withdrawn; no take-off request was recorded. Please try again.',
                        );

                        return new DataResponse([...
                            $answer,
                            'transferred' => false,
                            'requestOffline' => false,
                            'manifest' => ['updated' => false],
                            'bridge' => $this->syncAuthorSources([$target]),
                        ]);
                    }
                    $this->review->setFields([$target], [
                        'downRequest' => ['at' => time(), 'sourceDeleted' => true],
                        // a new request is not the previous decision: the old note and its author go
                        'note' => '',
                        'decidedBy' => '',
                    ]);

                    return new DataResponse([...
                        $answer,
                        'mode_applied' => true,
                        'transferred' => false,
                        'requestOffline' => true,
                        'reason' => $reason,
                        'manifest' => ['updated' => true],
                        'bridge' => $this->syncAuthorSources([$target]),
                    ]);
                }

                // keep: the published copy becomes the site's own file under the orphan owner, the
                // manifest entry is forgotten by the handover, and the old address leaves the site
                // in the same step
                $transfer = $this->orphanTransfer->transfer($target);

                return new DataResponse([...
                    $answer,
                    'mode_applied' => true,
                    'transferred' => true,
                    'requestOffline' => false,
                    'reason' => null,
                    'transfer' => $transfer,
                    'manifest' => ['updated' => true],
                    'bridge' => $this->syncAuthorSources([$target]),
                ]);
            });
        }

        return $this->notAnAuthorPath($path);
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
        if (($author = $this->requireAuthor()) !== null) {
            return $author;
        }
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

        return $this->notAnAuthorPath($path);
    }

    /**
     * Rename / move: its own route because POST /file means "write file content", and a rename
     * there would blur what "already exists" refers to.
     *
     * For an author both ends are targets of their own area, which is what keeps a rename inside the
     * namespace the payload can address. Renaming a file the site serves is not a silent address
     * change: the old address records a take-off request (`downRequest` with `sourceDeleted: true`,
     * {@see AuthorDeltaService::renamed()}), the new name starts as a draft (its path is new, see
     * `registerArticle()`), and the commit tree only gets the new target once it is approved - so the
     * new address ships with a review, exactly like a new file does.
     *
     * The target's file name may carry the article's day: the same "no later than today" rule the
     * structured save applies to the `date` field is applied here to the name (see
     * `nameDayNotAfterToday()`). The check runs before the rename, so a refused move leaves both ends
     * exactly where they were.
     */
    #[NoAdminRequired]
    public function move(string $from = '', string $to = ''): DataResponse
    {
        if (($author = $this->requireAuthor()) !== null) {
            return $author;
        }
        // Both ends belong to the caller's own author area: the mapper refuses anything else, and a
        // rename can therefore never move a file into (or out of) the site's source folder.
        if ($this->isAuthorScoped($from) && $this->isAuthorScoped($to)) {
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

        return $this->notAnAuthorPath($from !== '' ? $from : $to);
    }

    /**
     * Keep the article index current on an author's own save. A brand new author has to be known to
     * the database, otherwise their first article is missing from every list until a scan happens to
     * run.
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
            // registering only refreshes the title/tags row: the visibility state is not touched, so
            // an approved article stays approved while its text is corrected
            $this->articleIndex->register($uid, $area, (string)$resolved['file'], $target);
        } catch (\Throwable) {
            // The file is already written at this point, so failing the save would tell the author that
            // something did not happen which did. What a failure here costs is bookkeeping: an article
            // that stays approved while its file changed, which the next save of that article (or the
            // next review action, which fails loudly when the manifest cannot be written) repairs.
        }
    }

    /**
     * The author-content delta service, resolved from the container like {@see BaseController::authorAreas()}.
     *
     * It is asked for one thing only here: recording that an approved article's content changed.
     */
    private function authorDeltas(): AuthorDeltaService
    {
        return Server::get(AuthorDeltaService::class);
    }

    /**
     * Whether an endpoint's path names an author area - the only namespace this controller serves.
     *
     * The path decides, not the caller's identity: a target that names an author area
     * (`<author_root>/<uid>/…`) is that area's file for everybody, and nothing else is served here at
     * all. The mapper decides whether the caller may reach *that* area (its own, or any for the
     * review-and-publish grant), which is the ownership rule every endpoint shares.
     */
    private function isAuthorScoped(string $path): bool
    {
        if (!$this->canEdit()) {
            // An author addresses their own area directly (`notes/a.md`), and the mapper resolves that
            // path inside it. There is no other namespace this app serves any more, so for a caller
            // without the site-wide grant every path is an author path.
            return true;
        }

        return $this->authorAreas()->uidOfTarget($path) !== null;
    }

    /**
     * The refusal for a path that is not inside an author area. The site's own source folder is not
     * editable through this app (its writers are the Files app / WebDAV, Import and Pull), so saying
     * why is better than a bare 403.
     */
    private function notAnAuthorPath(string $path): DataResponse
    {
        return new DataResponse(
            [
                'error' => 'NOT_AN_AUTHOR_PATH',
                'message' => $this->l10n->t('This app edits your own author area only; the site source is edited in the Files app, or replaced by Import and Pull.'),
                'path' => $path,
            ],
            403,
        );
    }

    /** The refusal for an article endpoint called with a path that is not an article of an author area. */
    private function notAnArticlePath(string $path): DataResponse
    {
        return new DataResponse(
            ['error' => 'BAD_REQUEST', 'message' => $this->l10n->t('Not an article path: %s', [$path])],
            400,
        );
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
                    'The category “%s” is not allowed: use lower-case letters, digits, underscore, / or hyphen.',
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
     * A date in an author target's file name.
     *
     * The date of an article is its front-matter `date` and, while no key is set, the `YYYY-MM-DD-`
     * prefix of its file name ({@see FrontMatter::date()}), so a name is the second way a date enters
     * an article: creating a file and renaming one (a move is the rename) both change it. A name that
     * puts the article in the future is the same accident the `date` field is refused for, so it is
     * refused here as well, with the instance's own day as the boundary (`isAfterToday()`, PHP runs in
     * UTC). Today and every earlier day are accepted, which is what keeps a rename that only changes
     * the slug working.
     *
     * Only an author target is held to this rule: the site skeleton's own `_posts/**` is not edited
     * through this app.
     *
     * @throws \InvalidArgumentException when the name carries a day later than today
     */
    private function nameDayNotAfterToday(string $path): void
    {
        $clean = ltrim(str_replace('\\', '/', trim($path)), '/');
        // the only targets this controller edits are <author_root>/<uid>/…: everything else is the
        // site's own tree, which is written by the Files app, Import and Pull
        $prefix = rtrim($this->authorAreas()->authorRoot(), '/') . '/';
        if ($prefix === '/' || !str_starts_with($clean, $prefix)) {
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
        $node['pushFailed'] = false;
        $node['published'] = false;
        $node['candidate'] = false;
        $node['candidateAt'] = 0;
        $node['downRequest'] = false;
        if (!$isDir && $node['target'] !== '') {
            // the author of the entry: the front end needs it to tell the caller's own entry from
            // somebody else's (the decision rules do not look at the owner: a reviewer may decide
            // about any entry, their own included, and `decidedBy` records who answered)
            $node['owner'] = $uid;
            $target = (string)$node['target'];
            $node['state'] = $this->review->stateIn($manifest, $target);
            // the direction of a pending request, for the row that offers "withdraw"
            $node['intent'] = $this->review->intentIn($manifest, $target);
            // `live` is the site's own answer (`online/<target>`), never the visibility state: an
            // article that a reviewer approved but that no publish has carried is not served yet
            $node['live'] = $this->review->isLive($target);
            $node['pushFailed'] = $node['live'] && $this->registry->getPublishPushFailure() !== null;
            // the folder-derived facts: whether the site serves a version, whether one waits in
            // pending/ (and since when), and whether a take-offline request is waiting
            $pending = $this->review->isPending($target);
            $pendingAt = $this->review->pendingStamp($target);
            $downRequest = ReviewStore::downRequestOf($manifest, $target);
            $node['published'] = $node['live'];
            $node['candidate'] = $pending;
            $node['candidateAt'] = $pendingAt;
            $node['downRequest'] = $downRequest !== null;
            // the request's other facts travel with the row: "requested on <date>" needs the moment,
            // and a rejected request shows whose decision and why
            $entry = ReviewStore::entryOf($manifest, $target);
            $node['submittedAt'] = $pendingAt > 0 ? $pendingAt : ($downRequest['at'] ?? 0);
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
     * is left to request. `online/<target>` is deliberately kept - the site still serves the page
     * until a reviewer takes it down, and the absence from the source folder is not a decision.
     *
     * A candidate still waiting in `pending/` is the exception: forgetting the entry would leave the
     * frozen copy with nothing a later decision can be written to (and with no entry a reviewer could
     * find), so the entry is kept and the answer says `pending => true` - the caller reports the
     * target in `failed` instead of claiming it was forgotten. The file is already deleted at this
     * point; that fact is not rolled back.
     *
     * A failure is reported in the answer instead of thrown: the file is already gone at this point,
     * and a stale manifest entry is repaired by the next scan, while an exception would tell the
     * author that the deletion did not happen.
     *
     * @param string $target the file as a site target
     * @return array{updated:bool,pending?:bool,message?:string}
     */
    private function forgetArticleEntry(string $target): array
    {
        $key = ltrim(trim(str_replace('\\', '/', $target)), '/');
        if ($key === '') {
            return ['updated' => true];
        }
        if ($this->review->isPending($key)) {
            return [
                'updated' => false,
                'pending' => true,
                'message' => $this->l10n->t('The waiting candidate could not be withdrawn; the entry was kept.'),
            ];
        }
        try {
            $this->review->forgetArticle([$key]);

            return ['updated' => true];
        } catch (\Throwable $e) {
            return ['updated' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * The bookkeeping of a deleted folder: every target the site still serves asks to be taken down,
     * every other target goes with the folder, and a target whose candidate could not be withdrawn is
     * left as it was and reported in `failed`.
     *
     * The rule itself is the manifest's ({@see ReviewStore::requestTakeDownForDeletedSources()}), and
     * it is the same one the file event applies to a single deleted file
     * ({@see AuthorDeltaService::deleted()}): a page the site serves must keep its entry, because that
     * entry is the only place a reviewer can take the page down from - and a take-down is the only
     * operation that clears `online/<target>`. The file event's `remove` delta drops the preview copy
     * only, so a folder whose published files were merely forgotten would keep their pages online for
     * good.
     *
     * A failure is reported in the answer instead of thrown, like {@see self::forgetArticleEntry()}
     * does: the folder is already deleted at this point, and a manifest that could not be written is
     * repaired by the next scan, while an exception would tell the author that the deletion did not
     * happen.
     *
     * @param string $target the folder as a site target
     * @return array{manifest:array{updated:bool,message?:string},offline_requests:int,forgotten:int,failed:list<string>}
     */
    private function requestTakeDownOfDeleted(string $target): array
    {
        $key = ltrim(trim(str_replace('\\', '/', $target)), '/');
        if ($key === '') {
            return [
                'manifest' => ['updated' => true],
                'offline_requests' => 0,
                'forgotten' => 0,
                'failed' => [],
            ];
        }
        try {
            $plan = $this->review->requestTakeDownForDeletedSources([$key], time());

            return [
                'manifest' => ['updated' => true],
                'offline_requests' => count($plan['requested']),
                'forgotten' => count($plan['forgotten']),
                'failed' => $plan['failed'],
            ];
        } catch (\Throwable $e) {
            return [
                'manifest' => ['updated' => false, 'message' => $e->getMessage()],
                'offline_requests' => 0,
                'forgotten' => 0,
                'failed' => [],
            ];
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
     * This is the exception mapper, **not** the gate: every caller has already passed the authoring
     * capability check at its method entry ({@see BaseController::requireAuthor()}), so wrapping the
     * body alone would never be an authorization.
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
}
