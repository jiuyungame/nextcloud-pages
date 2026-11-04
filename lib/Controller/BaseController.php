<?php

declare(strict_types=1);

namespace OCA\Pages\Controller;

use OCA\Pages\AppInfo\Application;
use OCA\Pages\Service\AuthorAreaService;
use OCA\Pages\Service\AuthorSourcesService;
use OCA\Pages\Service\BridgeClient;
use OCA\Pages\Service\BridgeException;
use OCA\Pages\Service\ConflictException;
use OCA\Pages\Service\FileService;
use OCA\Pages\Service\NotFoundException;
use OCA\Pages\Service\RateLimitException;
use OCA\Pages\Service\SiteRegistry;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Server;

/**
 * Base class for every controller: the capability guards (admin / author / review / publish /
 * signed-in), mapping of service exceptions to structured JSON errors, and the current-user helper.
 *
 * There are two grants beside the administrator: **authoring** (write in one's own folder) and
 * **review-and-publish** (decide about visibility requests *and* publish them - one permission since
 * the publish grant was merged into review).
 */
abstract class BaseController extends Controller
{
    /**
     * Seconds a client is asked to wait after the bridge did not answer at all. Sent as `Retry-After`
     * on the one retryable bridge failure (see {@see BridgeException::isRetryable()}); the same order of
     * magnitude as the bridge's own debounce window, so a retry cannot arrive mid-rebuild.
     */
    protected const RETRY_AFTER_UNREACHABLE = 30;

    /** Per-request memoisation: the same decision is asked several times while one request runs. */
    private ?bool $cachedCanReview = null;

    public function __construct(
        string $appName,
        IRequest $request,
        protected IUserSession $userSession,
        protected IGroupManager $groupManager,
        protected SiteRegistry $registry,
        protected FileService $files,
        protected BridgeClient $bridge,
        protected IL10N $l10n,
    ) {
        parent::__construct($appName, $request);
    }

    /** Admin only; returns null when allowed, otherwise the response to return. */
    protected function requireAdmin(): ?DataResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['error' => 'UNAUTHENTICATED', 'message' => $this->l10n->t('Not logged in')], Http::STATUS_UNAUTHORIZED);
        }
        if (!$this->groupManager->isAdmin($user->getUID())) {
            return new DataResponse(
                ['error' => 'FORBIDDEN', 'message' => $this->l10n->t('Pages is restricted to administrators')],
                Http::STATUS_FORBIDDEN,
            );
        }
        return null;
    }

    protected function isAdmin(): bool
    {
        $user = $this->userSession->getUser();
        return $user !== null && $this->groupManager->isAdmin($user->getUID());
    }

    /**
     * The edit capability. One grant covers both halves of the job now: the review grant is 审核与发布,
     * so "write the site's own files / build / push" and "decide about a request" are the same
     * permission. Kept as its own name because the endpoints that ask
     * it mean the site's own tree, which may become a separate question again.
     */
    protected function canEdit(): bool
    {
        return $this->canReview();
    }

    /**
     * The one grant beside authoring: an administrator, or a member of one of the groups granted
     * reviewing. 审核与发布 — deciding about a visibility request, the direct take-down, comment
     * moderation, and publishing (building, diffing, pushing, the site's own files and other authors'
     * areas). The retired `publish_groups` list is not read any more: this grant carries publishing.
     */
    protected function canReview(): bool
    {
        if ($this->cachedCanReview === null) {
            $this->cachedCanReview = $this->isAdmin() || $this->isInGroupList($this->registry->getReviewGroups());
        }
        return $this->cachedCanReview;
    }

    /** Editors only (administrators are editors): returns null when allowed, otherwise the response. */
    protected function requireEditor(): ?DataResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['error' => 'UNAUTHENTICATED', 'message' => $this->l10n->t('Not logged in')], Http::STATUS_UNAUTHORIZED);
        }
        if (!$this->canEdit()) {
            return new DataResponse(
                ['error' => 'FORBIDDEN', 'message' => $this->l10n->t('You do not have permission to edit or publish.')],
                Http::STATUS_FORBIDDEN,
            );
        }
        return null;
    }

    /**
     * Reviewers only: deciding about articles and publishing them, which is one grant (审核与发布).
     */
    protected function requireReviewer(): ?DataResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['error' => 'UNAUTHENTICATED', 'message' => $this->l10n->t('Not logged in')], Http::STATUS_UNAUTHORIZED);
        }
        if (!$this->canReview()) {
            return new DataResponse(
                ['error' => 'FORBIDDEN', 'message' => $this->l10n->t('You do not have permission to review or publish articles.')],
                Http::STATUS_FORBIDDEN,
            );
        }
        return null;
    }

    /** Group membership test for the grants above; administrators are handled by canReview(). */
    private function isInGroupList(array $gids): bool
    {
        $user = $this->userSession->getUser();
        if ($user === null || $gids === []) {
            return false;
        }
        return array_intersect($this->groupManager->getUserGroupIds($user), $gids) !== [];
    }

    /**
     * The authoring capability: everyone who may keep posts in their own folder. An administrator and
     * any holder of the review grant (审核与发布) may author, and without a granted group list (the
     * default) every signed-in user may. The list is a real gate in the other direction: as soon as it
     * names a group, only that group may author.
     */
    protected function canAuthor(): bool
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return false;
        }
        if ($this->canEdit()) {
            return true;
        }
        $groups = $this->registry->getAuthorGroups();

        return $groups === [] || array_intersect($this->groupManager->getUserGroupIds($user), $groups) !== [];
    }

    /** Authoring users only; a publisher or administrator always passes. */
    protected function requireAuthor(): ?DataResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['error' => 'UNAUTHENTICATED', 'message' => $this->l10n->t('Not logged in')], Http::STATUS_UNAUTHORIZED);
        }
        if (!$this->canAuthor()) {
            return new DataResponse(
                ['error' => 'FORBIDDEN', 'message' => $this->l10n->t('You are not allowed to author posts on this site.')],
                Http::STATUS_FORBIDDEN,
            );
        }
        return null;
    }

    /**
     * Whether the caller may touch any author area, not just their own. The deciding capability is
     * the review-and-publish grant (`canReview()`) - the same one that opens the site tree and the
     * skeleton; authoring alone does not widen a caller beyond their own namespace.
     */
    protected function canTouchAuthorAreas(): bool
    {
        return $this->canReview();
    }

    /** The author space mapper, used by the endpoints that address files as article targets. */
    protected function authorAreas(): AuthorAreaService
    {
        return Server::get(AuthorAreaService::class);
    }

    /**
     * Push the site configuration and let the bridge rebuild.
     *
     * Author content is no longer part of this payload: the steady state is one file-event delta per
     * change ({@see \OCA\Pages\Service\AuthorDeltaService}), and the one-shot overwrite carries the
     * complete author state. The call therefore refreshes the bridge's site configuration after a
     * save; `$drop` is still accepted for the call sites that name a deletion, but there is no author
     * block left to apply it to. The file itself is already saved, so a failure is reported and never
     * thrown.
     *
     * @param list<string> $drop target paths the caller deleted (kept for the call sites, not sent)
     * @return array<string,mixed>
     */
    protected function syncAuthorSources(array $drop = []): array
    {
        try {
            // the push is the site configuration only: author content travels through the delta service,
            // and the one-shot overwrite carries the complete state; there is no author block left for
            // $drop to apply to
            $outcome = Server::get(AuthorSourcesService::class)->pushWithAuthors($this->bridge, null, $drop);
            $notified = $this->bridge->notify();
            // a push that reached the bridge clears the record of the last one that did not: the doctor
            // reports the newest failure only, not a history of outages
            $this->registry->recordPushFailure(null);

            return [
                'pushed' => true,
                'authors' => $outcome['authors'],
                'errors' => $outcome['errors'],
                'revision' => $outcome['result']['revision'] ?? null,
                'notified' => true,
                'result' => $notified,
            ];
        } catch (\Throwable $e) {
            // the file is already saved; the failure is reported to the caller *and* kept, because the
            // entry it belongs to is only marked in the browser - the next page load would otherwise
            // forget that this author has a file the bridge never received
            $code = $e instanceof BridgeException ? $e->getBridgeCode() : 'ERROR';
            $retryable = $e instanceof BridgeException && $e->isRetryable();
            $this->registry->recordPushFailure([
                'author' => (string)($this->userSession->getUser()?->getUID() ?? ''),
                'code' => $code,
                'message' => $e->getMessage(),
                'retryable' => $retryable,
            ]);

            return [
                'pushed' => false,
                'notified' => false,
                'error' => $code,
                'message' => $e->getMessage(),
                // the client needs the distinction to say whether trying again can help at all
                'retryable' => $retryable,
            ];
        }
    }

    protected function currentUserId(): string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new \RuntimeException($this->l10n->t('Not logged in'));
        }
        return $user->getUID();
    }

    /**
     * The user whose files hold the site source: the configured "site storage user", not the caller.
     * The app reads and writes that folder at the application level, which is exactly why a granted
     * editor needs no Nextcloud share on it (§4.3). An empty setting falls back to the caller, so an
     * instance that has not been configured yet still behaves sensibly.
     *
     * @param array<string,mixed> $site
     */
    protected function siteUserId(array $site): string
    {
        $uid = trim((string)($site['nc_user'] ?? ''));

        return $uid !== '' ? $uid : $this->currentUserId();
    }

    /** Maps known service exceptions to JSON errors; null means "unknown, rethrow it". */
    protected function translate(\Throwable $e): ?DataResponse
    {
        if ($e instanceof BridgeException) {
            if ($e->getBridgeCode() === 'CONFIRM_REQUIRED') {
                // the confirmation dialog reads `exists` next to the code, like the bridge's own payload
                return new DataResponse(
                    ['error' => 'CONFIRM_REQUIRED', 'message' => $e->getMessage()] + $e->getDetails(),
                    Http::STATUS_CONFLICT,
                );
            }
            $status = match ($e->getBridgeCode()) {
                'NOT_CONFIGURED' => Http::STATUS_PRECONDITION_FAILED,
                'PROTOCOL_MISMATCH' => Http::STATUS_PRECONDITION_FAILED,
                'UNREACHABLE' => Http::STATUS_SERVICE_UNAVAILABLE,
                'MIRROR_DELETE_GUARD' => Http::STATUS_CONFLICT,
                'CONFIRM_REQUIRED' => Http::STATUS_CONFLICT,
                // the bytes under review are no longer the approved ones: the caller has to ask for a
                // new request, not retry the same call
                'DIGEST_MISMATCH' => Http::STATUS_CONFLICT,
                // the commit tree is not the source/ + online/ assembly, and the bridge refused the
                // publish before doing anything: the tree has to be rebuilt, so the same call would
                // only repeat the verdict
                'WORK_DRIFT' => Http::STATUS_CONFLICT,
                // the author content directory could not be moved: the bridge's move is all or
                // nothing, so it put the files it had already moved back under the old name and kept
                // the old root, and the app put its stored value back too - nothing is in force on
                // either side, and the same call can be repeated
                'AUTHOR_ROOT_MOVE_FAILED' => Http::STATUS_CONFLICT,
                default => Http::STATUS_BAD_GATEWAY,
            };
            $response = new DataResponse($e->toArray(), $status);
            if ($e->isRetryable()) {
                // The one retryable failure (the bridge did not answer) says when to try again: the
                // same header a rate limit already carries, because a client that is told "later"
                // needs to know how much later instead of guessing or hammering.
                $response->addHeader('Retry-After', (string)self::RETRY_AFTER_UNREACHABLE);

                return $response;
            }

            return $response;
        }
        if ($e instanceof ConflictException) {
            return new DataResponse($e->toArray(), Http::STATUS_CONFLICT);
        }
        if ($e instanceof NotFoundException) {
            return new DataResponse(['error' => 'NOT_FOUND', 'message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
        if ($e instanceof RateLimitException) {
            // the wait travels with the answer: a client that is told to slow down needs to know for
            // how long, and `Retry-After` is what an HTTP client already understands
            $response = new DataResponse(
                [
                    'error' => 'TOO_MANY_REQUESTS',
                    'message' => $e->getMessage(),
                    'retryAfter' => $e->getRetryAfter(),
                ],
                Http::STATUS_TOO_MANY_REQUESTS,
            );
            $response->addHeader('Retry-After', (string)$e->getRetryAfter());

            return $response;
        }
        if ($e instanceof \OCP\Files\NotFoundException) {
            return new DataResponse(['error' => 'NOT_FOUND', 'message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
        if ($e instanceof \OCP\Lock\LockedException) {
            // A site folder (or a parent of it) was held by another request: the same conflict a
            // failed replacement reports, so a client sees one status for "try again shortly"
            // whichever path the lock reached it through. The lock's own text names the path.
            $message = $e->getMessage();

            return new DataResponse(
                [
                    'error' => 'SITE_LOCKED',
                    'message' => $message !== '' ? $message : $this->l10n->t('The site folder is locked by another request.'),
                ],
                Http::STATUS_CONFLICT,
            );
        }
        if ($e instanceof \OCP\Files\NotPermittedException) {
            return new DataResponse(['error' => 'FORBIDDEN', 'message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        }
        if ($e instanceof \InvalidArgumentException) {
            return new DataResponse(['error' => 'BAD_REQUEST', 'message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
        return null;
    }

    /**
     * A failed author-root move as the administrator has to read it.
     *
     * The bridge's move is all or nothing and runs **before** it adopts the new root: on a 409
     * `AUTHOR_ROOT_MOVE_FAILED` it has already put the files it moved back under the old name, the old
     * root stays in force there, and this app has put its stored value back as well. The common answer
     * is therefore "nothing was changed" - but the rollback is best effort. `rollback_failed` names the
     * files that could not be put back: they are still under the new name and are not part of the site,
     * so that sentence would be false exactly when an administrator has something to clean up. The
     * bridge's code, status and details travel unchanged in both cases; only the sentence in front of
     * its message depends on the list.
     */
    protected function authorRootMoveFailed(\Throwable $e): \Throwable
    {
        if (!$e instanceof BridgeException || $e->getBridgeCode() !== 'AUTHOR_ROOT_MOVE_FAILED') {
            return $e;
        }

        $details = $e->getDetails();
        $rollbackFailed = [];
        $reported = $details['rollback_failed'] ?? null;
        foreach (is_array($reported) ? $reported : [] as $path) {
            if (is_string($path) && $path !== '') {
                $rollbackFailed[] = $path;
            }
        }
        if ($rollbackFailed === []) {
            return new BridgeException(
                $this->l10n->t('The author content directory could not be moved; nothing was changed. %s', [$e->getMessage()]),
                $e->getBridgeCode(),
                $details,
                $e->getHttpStatus(),
            );
        }

        // The bridge moved `moved` files and then tried to put all of them back; the ones it could not
        // are exactly `rollback_failed`. Without the counts (an older answer) `remaining` is the only
        // size the answer carries, so the sentence uses it instead of inventing a number.
        $moved = $details['moved'] ?? null;
        $movedCount = null;
        if (is_array($moved)) {
            $movedCount = 0;
            foreach ($moved as $count) {
                if (is_int($count)) {
                    $movedCount += $count;
                } elseif (is_string($count) && ctype_digit($count)) {
                    $movedCount += (int)$count;
                }
            }
        } elseif (is_int($moved)) {
            $movedCount = $moved;
        }
        $remaining = $details['remaining'] ?? null;
        $putBack = $movedCount !== null
            ? max(0, $movedCount - count($rollbackFailed))
            : (is_array($remaining) ? count($remaining) : 0);
        $sample = array_slice($rollbackFailed, 0, 3);

        return new BridgeException(
            $this->l10n->t(
                'The author content directory could not be moved; %d file(s) were put back, but %d file(s) are still under the new name and are not part of the site: %s. Retry the change, or move them back by hand.',
                [$putBack, count($rollbackFailed), implode(', ', $sample)],
            ),
            $e->getBridgeCode(),
            $details,
            $e->getHttpStatus(),
        );
    }

    /**
     * Site context: enforces the capability of the calling endpoint and loads the single site.
     *
     * `$capability` is one of:
     *   `author`          administrators, authors                      (own articles, submitting)
     *   `review`          administrators, reviewers                     (queue, decisions, moderation)
     *   `edit`            administrators, reviewers+publishers          (files, build, notify)
     *   `publish`         administrators, reviewers+publishers          (diff, publish)
     *   `editOrPublish`   administrators, reviewers+publishers          (status, log)
     *   `view`            any signed-in user                            (preview session)
     *   `admin`           administrators only                           (init, pull)
     *
     * `edit`, `publish` and `editOrPublish` are one grant (审核与发布): the three names survive because
     * an endpoint asks a different question with each - which file tree, whether to build, whether to
     * commit - and they may be separated again. They share one guard, so no capability can drift.
     * An unknown value falls back to `admin`, so a typo can never widen access.
     *
     * @return array{0:?DataResponse,1:?array<string,mixed>,2:string}
     */
    protected function siteContext(string $capability = 'edit'): array
    {
        $denied = match ($capability) {
            'view' => $this->requireUser(),
            'author' => $this->requireAuthor(),
            'review' => $this->requireReviewer(),
            'edit', 'publish', 'editOrPublish' => $this->requireEditor(),
            default => $this->requireAdmin(),
        };
        if ($denied !== null) {
            return [$denied, null, ''];
        }
        $site = $this->registry->getSite();
        if ($site === null) {
            // single-site: there is no id that could be wrong, only a configuration that is missing
            return [
                new DataResponse(
                    ['error' => 'NOT_CONFIGURED', 'message' => $this->l10n->t('No site is configured yet')],
                    Http::STATUS_PRECONDITION_FAILED,
                ),
                null,
                '',
            ];
        }
        return [null, $site, $this->siteUserId($site)];
    }

    /**
     * No capability at all: the preview is open to every signed-in user, so this only refuses
     * anonymous requests. Everything else is decided by the bridge token.
     */
    protected function requireUser(): ?DataResponse
    {
        if ($this->userSession->getUser() === null) {
            return new DataResponse(['error' => 'UNAUTHENTICATED', 'message' => $this->l10n->t('Not logged in')], Http::STATUS_UNAUTHORIZED);
        }
        return null;
    }

    protected function template(string $name, array $params = [], string $renderAs = TemplateResponse::RENDER_AS_USER): TemplateResponse
    {
        return new TemplateResponse(Application::APP_ID, $name, $params, $renderAs);
    }
}
