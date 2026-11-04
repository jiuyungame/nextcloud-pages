<?php

declare(strict_types=1);

namespace OCA\Pages\Controller;

use OCA\Pages\AppInfo\Application;
use OCA\Pages\Service\ArticleReviewService;
use OCA\Pages\Service\BridgeClient;
use OCA\Pages\Service\FileService;
use OCA\Pages\Service\SiteRegistry;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The visibility endpoints: a page is internal until a reviewer approves it for the site.
 *
 *   POST /api/article/request         {path, intent: 'online'|'offline'}   author of the entry, or a grant
 *   POST /api/article/cancel          {path}                               author of the entry, or a grant
 *   POST /api/article/decision        {path, decision: 'approve'|'reject', note?}   review grant
 *   POST /api/article/take-down       {path, note?}                        review grant
 *
 * An **online** request is what asks for content to go out: the bridge freezes the file's current
 * bytes into the review tree, and a reviewer's approval publishes exactly those bytes. That is also
 * how an online page asks for its new content to replace what the site serves - there is no separate
 * update request any more, and an edit alone never enters the queue: the author decides whether the
 * new content should go out, and the file on disk keeps changing until they ask.
 *
 * An **offline** request is only the intention to take the page off the site; the reviewer's approval
 * carries it out. A take-down is the reviewer's own action for a page with nothing pending.
 *
 * Publishing itself stays where it was (`POST /api/site/publish`), because it is the same git commit:
 * it stages everything the site has a version of plus the removals of whatever it no longer has, and
 * commits the site's own files for a caller who may also edit.
 *
 * Reading the pending requests needs no endpoint of its own: they are the `requested` entries of the
 * article list (`GET /api/articles?state=requested`, or `?awaiting=1` for the entries with a frozen
 * candidate), which every signed-in user may read. Deciding them and publishing them are one grant
 * (审核与发布): whoever may decide about somebody's
 * article may also publish what was decided.
 *
 * A decision is recorded before the article cache is refreshed, so a cache failure is not an error
 * answer: the four actions below answer 200 with `cache_synced: false` and `cache_message` saying why
 * (see {@see \OCA\Pages\Service\ReviewStore::lastCacheSyncFailed()}), because the decision is already in force
 * and retrying it would only answer 409 ("no request is pending"). A client should tell the user the
 * decision was recorded and the queue is still catching up, not that the action failed.
 */
class ReviewController extends BaseController
{
    public function __construct(
        IRequest $request,
        IUserSession $userSession,
        IGroupManager $groupManager,
        SiteRegistry $registry,
        FileService $files,
        BridgeClient $bridge,
        IL10N $l10n,
        private ArticleReviewService $review,
    ) {
        parent::__construct(Application::APP_ID, $request, $userSession, $groupManager, $registry, $files, $bridge, $l10n);
    }

    /**
     * Ask for a change of visibility: an author for their own page, or a holder of the review grant
     * (审核与发布) for anybody's. Filing is not deciding - the entry waits in `requested` until a
     * reviewer answers, and nothing about the site changes before that.
     *
     * An authoring grant is required as well (for the author's own route): the grant decides who may
     * keep pages in their own folder, so a user outside it does not start a visibility change either.
     * A reviewer passes without it, because filing a request for somebody else is their job.
     */
    #[NoAdminRequired]
    public function request(): DataResponse
    {
        $author = $this->requireAuthor();
        if ($author !== null && !$this->canReview()) {
            return $author;
        }

        return $this->body(function (string $uid): array {
            return $this->review->request(
                $uid,
                $this->pathParam(),
                trim((string)$this->request->getParam('intent', '')),
                $this->canReview(),
            );
        });
    }

    /** Withdraw the pending request; the entry returns to where its intent started. */
    #[NoAdminRequired]
    public function cancel(): DataResponse
    {
        // the same authoring gate as filing a request: a user outside the grant may not start a
        // visibility change, and withdrawing one is not the exception. A reviewer passes without it,
        // because a reviewer may have filed the request for somebody else.
        $author = $this->requireAuthor();
        if ($author !== null && !$this->canReview()) {
            return $author;
        }

        return $this->body(fn (string $uid): array => $this->review->cancel(
            $uid,
            $this->pathParam(),
            $this->canReview(),
        ));
    }

    /**
     * The reviewer's answer. `approve` accepts what the author asked for: a pending online request
     * publishes its frozen candidate, a pending offline request takes the published version off the
     * site. `reject` answers the request without doing what it asked, with the note as the reason - a
     * refused online request discards the candidate and keeps whatever the site already serves, a
     * refused offline request leaves the page where it is. Nothing pending is a conflict; the
     * reviewer's direct take-down has its own endpoint ({@see self::takeDown()}).
     */
    #[NoAdminRequired]
    public function decision(): DataResponse
    {
        [$denied] = $this->siteContext('review');
        if ($denied !== null) {
            return $denied;
        }
        $decision = trim((string)$this->request->getParam('decision', ''));
        $note = trim((string)$this->request->getParam('note', ''));
        try {
            $answer = $this->review->decide($this->currentUserId(), $this->pathParam(), $decision, $note);
        } catch (\Throwable $e) {
            return $this->translate($e) ?? throw $e;
        }

        // The bridge operation travelled with the decision itself - publish, discard or take-down -
        // so no author delta follows: the review tree, the commit tree, `online/` and the manifest
        // were moved together by the service. What still follows is the site configuration push and
        // the rebuild ping, which is what makes the built site reflect the new state.
        return new DataResponse([
            'ok' => true,
            ...$answer,
            // nothing waits for a decision after one; the snapshot keys stay so a client can refresh
            // a row without re-reading the list
            'candidate' => false,
            'downRequest' => false,
            'counts' => $this->review->counts(),
            'bridge' => $this->syncAuthorSources(),
        ]);
    }

    /**
     * The reviewer's direct take-down (下线): take a page the site serves off it, with nothing pending.
     *
     * It answers no request, so it is its own endpoint and not a `reject`: a reviewer acts on their
     * own judgement, and the author is told afterwards with the note as the reason. The bridge drops
     * the target from the commit tree and from `online/` in one operation, and the manifest drops the
     * entry (when the source file is gone as well) or clears the request that was waiting for it, so
     * the next publish carries the removal out of the commit. The article file itself stays in the
     * author's folder - an internal page is still previewable and editable.
     */
    #[NoAdminRequired]
    public function takeDown(): DataResponse
    {
        [$denied] = $this->siteContext('review');
        if ($denied !== null) {
            return $denied;
        }
        $note = trim((string)$this->request->getParam('note', ''));
        try {
            $answer = $this->review->takeDown($this->currentUserId(), $this->pathParam(), $note);
        } catch (\Throwable $e) {
            return $this->translate($e) ?? throw $e;
        }

        return new DataResponse([
            'ok' => true,
            ...$answer,
            'candidate' => false,
            'downRequest' => false,
            'counts' => $this->review->counts(),
            'bridge' => $this->syncAuthorSources(),
        ]);
    }

    /**
     * One endpoint body the caller may reach without a capability of its own: the service decides who
     * may file or withdraw this one (the entry's author, or a grant holder).
     *
     * @param callable(string):array<string,mixed> $action gets the caller's uid
     */
    private function body(callable $action): DataResponse
    {
        [$denied] = $this->siteContext('view');
        if ($denied !== null) {
            return $denied;
        }
        try {
            $answer = $action($this->currentUserId());
        } catch (\Throwable $e) {
            return $this->translate($e) ?? throw $e;
        }

        return new DataResponse(['ok' => true, ...$answer, 'counts' => $this->review->counts()]);
    }

    /**
     * The article one of the endpoints names. Only a string is a path: a JSON body can carry a list or
     * an object under `path`, and neither is one.
     *
     * @throws \InvalidArgumentException when nothing usable was sent
     */
    private function pathParam(): string
    {
        $path = $this->request->getParam('path', '');
        if (!is_string($path) || trim($path) === '') {
            throw new \InvalidArgumentException($this->l10n->t('A path is required'));
        }

        return trim($path);
    }
}
