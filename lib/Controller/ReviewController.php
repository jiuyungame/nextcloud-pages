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
 *   POST /api/article/request   {path, intent: 'online'|'offline'}   author of the entry, or a grant
 *   POST /api/article/cancel    {path}                               author of the entry, or a grant
 *   POST /api/article/decision  {path, decision: 'approve'|'reject', note?}   review grant
 *
 * The three actions are the whole author/reviewer conversation; publishing itself stays where it was
 * (`POST /api/site/publish`), because it is the same git commit: it stages everything `external` plus
 * the removals of whatever is no longer external, and commits the site's own files for a caller who
 * may also edit.
 *
 * Reading the pending requests needs no endpoint of its own: they are the `requested` entries of the
 * article list (`GET /api/articles?state=requested`), which every signed-in user may read. Deciding
 * them and publishing them are one grant (审核与发布, see \OCA\Pages\Service\PermissionMigration):
 * whoever may decide about somebody's article may also publish what was decided.
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
        return $this->body(fn (string $uid): array => $this->review->cancel(
            $uid,
            $this->pathParam(),
            $this->canReview(),
        ));
    }

    /**
     * The reviewer's answer. `approve` applies the intent (`online` → `external`, `offline` →
     * `internal`); `reject` puts the entry back to `internal` with the note, and on an `external` entry
     * it is the direct take-down (下线).
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

        // A decision is the moment the mirror lists change: an approval puts the file into the
        // commit tree, a rejection takes it back out of it. The push below carries both lists, so a
        // taken-down page simply disappears from the on-site list while staying in the preview list;
        // it is *not* passed as `drop` - that names a file that no longer exists in the author area,
        // and a take-down keeps the file. Dropping it would delete the page from the preview tree
        // too, which would make an internal page un-previewable.
        return new DataResponse([
            'ok' => true,
            ...$answer,
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
