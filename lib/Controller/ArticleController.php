<?php

declare(strict_types=1);

namespace OCA\Pages\Controller;

use OCA\Pages\AppInfo\Application;
use OCA\Pages\Db\Article;
use OCA\Pages\Service\ArticleIndexService;
use OCA\Pages\Service\ArticleReviewService;
use OCA\Pages\Service\BridgeClient;
use OCA\Pages\Service\EngagementService;
use OCA\Pages\Service\FileService;
use OCA\Pages\Service\SiteRegistry;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Reading articles and the community view around them. Reading needs no capability — like the
 * preview, it is open to every signed-in user — and it has no visibility filter either: every entry
 * that exists and belongs to the site is listed, with its state. Writing (creating, requesting a
 * visibility change, voting, commenting) is gated per endpoint.
 */
class ArticleController extends BaseController
{
    private const SORTS = ['new', 'score', 'comments'];

    public function __construct(
        IRequest $request,
        IUserSession $userSession,
        IGroupManager $groupManager,
        SiteRegistry $registry,
        FileService $files,
        BridgeClient $bridge,
        IL10N $l10n,
        private ArticleIndexService $articleIndex,
        private EngagementService $community,
        private ArticleReviewService $review,
    ) {
        parent::__construct(Application::APP_ID, $request, $userSession, $groupManager, $registry, $files, $bridge, $l10n);
    }

    /**
     * The article list: every page of the site, optionally filtered by tag, by visibility state, by
     * "nothing is pending on it" (`?external=true`, i.e. the `external` state) and by "a frozen
     * candidate waits for a decision" (`?awaiting=true`), and sorted.
     *
     * A filter is answered here rather than in the browser: the list can be truncated, and filtering a
     * truncated list in the client would quietly show "nothing matches" for rows that were never sent.
     *
     * `awaiting` wins over `state` when both are sent: it is the narrower question (an entry with a
     * candidate is `requested`, and an online page with a candidate keeps its old version on the site)
     * and the two filters together would answer a third one.
     */
    #[NoAdminRequired]
    public function index(): DataResponse
    {
        [$denied] = $this->siteContext('view');
        if ($denied !== null) {
            return $denied;
        }
        try {
            $awaiting = $this->awaitingParam();
            $result = $this->articleIndex->listed(
                $this->currentUserId(),
                $this->tagParam(),
                $this->sortParam(),
                $awaiting ? '' : $this->stateParam(),
                $this->externalParam(),
                $awaiting,
            );

            // The review counts ride along: the requests panel shows how many pages are approved for
            // the site, and querying the article table again for that one number would be a second
            // read of data the same page is about to show anyway. A read failure answers zeroes,
            // which is the counts() contract; it never turns the list into an error.
            return new DataResponse([...$result, 'counts' => $this->review->counts()]);
        } catch (\Throwable $e) {
            return $this->translate($e) ?? throw $e;
        }
    }

    /**
     * The requested visibility state, or '' for "any state". An unknown state is refused instead of
     * being ignored: silently answering a different question than the one asked is worse than an error.
     */
    private function stateParam(): string
    {
        $state = trim((string)$this->request->getParam('state', ''));
        if ($state === '') {
            return '';
        }
        if (!in_array($state, Article::STATES, true)) {
            throw new \InvalidArgumentException($this->l10n->t('Unknown state: %s', [$state]));
        }

        return $state;
    }

    /** `?external=true` (or `1`): only the entries approved for the site. Anything else means "all". */
    private function externalParam(): bool
    {
        return filter_var($this->request->getParam('external', false), FILTER_VALIDATE_BOOLEAN) === true;
    }

    /**
     * `?awaiting=true` (or `1`): only the entries with a frozen candidate waiting for a decision - the
     * requests the panel lists. The bytes were frozen when the author asked, so the reviewer decides
     * about exactly that content; an entry whose author is still editing has no candidate and is not
     * one.
     */
    private function awaitingParam(): bool
    {
        return filter_var($this->request->getParam('awaiting', false), FILTER_VALIDATE_BOOLEAN) === true;
    }

    /**
     * The caller's own articles, whatever state they are in (drafts included).
     */
    #[NoAdminRequired]
    public function mine(): DataResponse
    {
        [$denied] = $this->siteContext('view');
        if ($denied !== null) {
            return $denied;
        }
        try {
            return new DataResponse($this->articleIndex->mine(
                $this->currentUserId(),
                $this->tagParam(),
                $this->sortParam(),
            ));
        } catch (\Throwable $e) {
            return $this->translate($e) ?? throw $e;
        }
    }

    /**
     * One article: metadata, community counters and the caller's own vote. The body is not returned
     * — readers see the built page through the preview, and authors edit the file itself.
     */
    #[NoAdminRequired]
    public function read(): DataResponse
    {
        [$denied] = $this->siteContext('view');
        if ($denied !== null) {
            return $denied;
        }
        $path = trim((string)$this->request->getParam('path', ''));
        if ($path === '') {
            return new DataResponse(['error' => 'BAD_REQUEST', 'message' => $this->l10n->t('A path is required')], 400);
        }
        try {
            $article = $this->articleIndex->single(
                $this->currentUserId(),
                $path,
                $this->canReview(),
            );
            if ($article === null) {
                return new DataResponse(
                    ['error' => 'NOT_FOUND', 'message' => $this->l10n->t('No such article')],
                    404,
                );
            }

            return new DataResponse(['article' => $article]);
        } catch (\Throwable $e) {
            return $this->translate($e) ?? throw $e;
        }
    }

    /**
     * Tags of every article in the reading list, most used first: the sidebar of the reading view.
     * Internal pages are included, because the list shows them and the filter must not hide their
     * tags.
     */
    #[NoAdminRequired]
    public function tags(): DataResponse
    {
        [$denied] = $this->siteContext('view');
        if ($denied !== null) {
            return $denied;
        }
        try {
            return new DataResponse(['tags' => $this->articleIndex->tagCounts($this->currentUserId())]);
        } catch (\Throwable $e) {
            return $this->translate($e) ?? throw $e;
        }
    }

    /**
     * Rates an article, or withdraws the caller's vote.
     *
     *   {"path": "/<author_root>/<uid>/x.md", "vote": "up"|"down"|"none"}
     *
     * One row per voter and article: a changed vote updates it, `none` deletes it. The answer carries
     * the article's new counters and the caller's own vote, so the reading view updates in place.
     * Voting needs no capability beyond being signed in, like reading itself; whether the caller may
     * see the article at all is decided by the index (see EngagementService::article()).
     */
    #[NoAdminRequired]
    public function vote(): DataResponse
    {
        [$denied] = $this->siteContext('view');
        if ($denied !== null) {
            return $denied;
        }
        $vote = trim((string)$this->request->getParam('vote', ''));
        $payload = $this->request->getParam('path', '');
        try {
            $article = $this->community->vote(
                $this->currentUserId(),
                is_string($payload) ? $payload : '',
                $vote,
                $this->canReview(),
            );
        } catch (\Throwable $e) {
            return $this->translate($e) ?? throw $e;
        }

        return new DataResponse(['ok' => true, 'article' => $article]);
    }

    /**
     * The comment thread of one article, oldest first: `?path=/<author_root>/<uid>/x.md`.
     *
     * A comment that was deleted is left out for readers and included with `deleted: true` for a caller
     * with the review grant, who is the one who moderated it.
     */
    #[NoAdminRequired]
    public function comments(): DataResponse
    {
        [$denied] = $this->siteContext('view');
        if ($denied !== null) {
            return $denied;
        }
        try {
            $thread = $this->community->comments(
                $this->currentUserId(),
                $this->pathParam(),
                $this->canReview(),
            );
        } catch (\Throwable $e) {
            return $this->translate($e) ?? throw $e;
        }

        return new DataResponse(['ok' => true, ...$thread]);
    }

    /**
     * Writes a comment: `{"path": "/<author_root>/<uid>/x.md", "body": "…"}`.
     *
     * The body is plain text - what the caller typed, trimmed, nothing else - and is handed back the
     * same way: the browser renders it as text, never as markup. The rate limit (see
     * EngagementService::checkRate()) is part of the feature: a second comment inside the cooldown is a
     * 429 that names the wait.
     */
    #[NoAdminRequired]
    public function addComment(): DataResponse
    {
        [$denied] = $this->siteContext('view');
        if ($denied !== null) {
            return $denied;
        }
        $body = $this->request->getParam('body', '');
        try {
            $written = $this->community->comment(
                $this->currentUserId(),
                $this->pathParam(),
                is_string($body) ? $body : '',
                $this->canReview(),
            );
        } catch (\Throwable $e) {
            return $this->translate($e) ?? throw $e;
        }

        return new DataResponse(['ok' => true, ...$written]);
    }

    /**
     * Deletes a comment: `{"id": 42}`.
     *
     * The author of the comment deletes their own, a caller with the review grant deletes any
     * (moderation). The deletion is soft - the row keeps its place and its text - so the thread stays
     * auditable while the comment disappears from every reader's view.
     */
    #[NoAdminRequired]
    public function deleteComment(): DataResponse
    {
        [$denied] = $this->siteContext('view');
        if ($denied !== null) {
            return $denied;
        }
        $id = filter_var($this->request->getParam('id', 0), FILTER_VALIDATE_INT);
        if ($id === false || $id <= 0) {
            return new DataResponse(['error' => 'BAD_REQUEST', 'message' => $this->l10n->t('A comment id is required')], 400);
        }
        try {
            $deleted = $this->community->deleteComment($this->currentUserId(), $id, $this->canReview());
        } catch (\Throwable $e) {
            return $this->translate($e) ?? throw $e;
        }

        return new DataResponse(['ok' => true, 'deleted' => true, ...$deleted]);
    }

    private function tagParam(): string
    {
        return trim((string)$this->request->getParam('tag', ''));
    }

    /**
     * The article a community request names. Only a string is accepted: a JSON body can carry a list or
     * an object under `path`, and neither is a path.
     */
    private function pathParam(): string
    {
        $path = $this->request->getParam('path', '');

        return is_string($path) ? $path : '';
    }

    private function sortParam(): string
    {
        $sort = trim((string)$this->request->getParam('sort', 'new'));

        return in_array($sort, self::SORTS, true) ? $sort : 'new';
    }
}
