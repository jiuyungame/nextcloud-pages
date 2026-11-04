<?php

declare(strict_types=1);

namespace OCA\Pages\Controller;

use OCA\Pages\AppInfo\Application;
use OCA\Pages\Service\AuthorSourcesService;
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
 * Build, Apply source and publish: thin proxies to the bridge, gated per endpoint by the one
 * review-and-publish grant of the caller (status and log are readable by either name of it).
 *
 * Publishing is the one endpoint that does not decide *what* is committed any more: the commit tree is
 * assembled from `source/` + `online/`, and a page enters `online/` only through an approval
 * (`publish` op). What a publish may carry is therefore a fact of the folders, not a path list this app
 * would have to reconstruct - so the request is the commit message and nothing else.
 */
class BuildController extends BaseController
{
    public function __construct(
        IRequest $request,
        IUserSession $userSession,
        IGroupManager $groupManager,
        SiteRegistry $registry,
        FileService $files,
        BridgeClient $bridge,
        IL10N $l10n,
        private AuthorSourcesService $authorSources,
    ) {
        parent::__construct(Application::APP_ID, $request, $userSession, $groupManager, $registry, $files, $bridge, $l10n);
    }

    /**
     * Every method here is gated by a capability inside the controller (siteContext() with
     * edit / publish / editOrPublish, which are the review grant), so the framework-level admin gate
     * has to be lifted for the route to be reachable at all.
     */
    #[NoAdminRequired]
    public function status(): DataResponse
    {
        return $this->proxy('editOrPublish', function (): array {
            $status = $this->bridge->siteStatus();
            // Where the site lives on the host is configuration metadata: an editor or publisher may
            // read the site's files and its build output, but not the absolute host paths (§3.5-1).
            if (!$this->isAdmin()) {
                unset(
                    $status['source'],
                    $status['source_dir'],
                    $status['online_dir'],
                    $status['pending_dir'],
                    $status['work'],
                    $status['preview'],
                    $status['output_dir'],
                    // the review tree and its build: the same host paths, one tree further (§3.5-1).
                    // `onlinepreview` is where a candidate's frozen bytes live, `onlinepreview_output`
                    // is its build, and the three bookkeeping keys name the last build/mirror of it.
                    $status['onlinepreview'],
                    $status['onlinepreview_output'],
                    $status['pending_onlinepreview_debounce'],
                    $status['last_onlinepreview_build'],
                    $status['last_onlinepreview_mirror'],
                );
                if (is_array($status['last_build'] ?? null)) {
                    unset($status['last_build']['output_dir']);
                }
                // the author sources carry the absolute host path of each author's folder, both in
                // the status itself and again inside the mirror report (`last_mirror`)
                if (array_key_exists('authors', $status)) {
                    $status['authors'] = self::withoutHostPaths($status['authors']);
                }
                if (is_array($status['last_mirror'] ?? null) && array_key_exists('authors', $status['last_mirror'])) {
                    $status['last_mirror']['authors'] = self::withoutHostPaths($status['last_mirror']['authors']);
                }
            }
            return $status;
        });
    }

    /**
     * Drops the host path from a list of per-author status entries; everything else is left alone.
     *
     * @param mixed $authors
     * @return list<mixed>
     */
    private static function withoutHostPaths(mixed $authors): array
    {
        if (!is_array($authors)) {
            return [];
        }
        $out = [];
        foreach ($authors as $author) {
            if (is_array($author)) {
                unset($author['source']);
            }
            $out[] = $author;
        }

        return $out;
    }

    /**
     * **Apply source**: rebuild the three derived trees from the Nextcloud folders and build the
     * preview.
     *
     * It sends the bridge's `mirror` op, naming every author area ({@see AuthorSourcesService::authorAreas()}):
     * the bridge layers exactly the areas it is given into the preview tree, so an author left out
     * would silently lose their drafts from the rebuilt tree. The answer is the bridge's own report:
     * `{rebuilt, files: {work, preview, onlinepreview}, build}`.
     *
     * `force` is the second half of the bridge's delete guard. The first call sends `false`; when the
     * bridge refuses a round that would delete most of a tree (`MIRROR_DELETE_GUARD`) the answer
     * carries the numbers the administrator has to see - `tree`, `old`, `new`, `removed` and the
     * `guard` that tripped - and the front end asks before it repeats the request with `force: true`.
     * `force` skips only that guard, never the bridge's source check: `SOURCE_EMPTY` and
     * `SOURCE_UNREADABLE` are data problems, so they are reported and are never retried with force.
     */
    #[NoAdminRequired]
    public function build(): DataResponse
    {
        return $this->proxy('edit', function (): array {
            // the front end sends `force: true` on the second, confirmed call only
            $force = $this->request->getParam('force', false);
            $force = $force === true || $force === 1 || $force === '1' || $force === 'true';
            $result = $this->bridge->mirror($this->authorSources->authorAreas(), true, false, false, $force);
            // the trees were just rebuilt: the cached directory scans of this request are stale
            $payload = [
                'rebuilt' => ($result['rebuilt'] ?? false) === true,
                'files' => is_array($result['files'] ?? null) ? $result['files'] : [
                    'work' => 0, 'preview' => 0, 'onlinepreview' => 0,
                ],
                'build' => is_array($result['build'] ?? null) ? $result['build'] : null,
            ];
            // A refused round is HTTP 200 with `ok: false` on the bridge side; the body has to keep
            // that verdict and the structured block, or the page would report a rebuild that did not
            // happen as a success. `guard` is what the confirmation dialog reads.
            if (($result['ok'] ?? true) === false) {
                $payload['ok'] = false;
                foreach (['error', 'message', 'tree', 'old', 'new', 'removed', 'guard'] as $key) {
                    if (array_key_exists($key, $result)) {
                        $payload[$key] = $result[$key];
                    }
                }
            }

            return $payload;
        });
    }

    /** Lightweight post-save notify: the bridge rebuilds after its debounce window. */
    #[NoAdminRequired]
    public function notify(): DataResponse
    {
        return $this->proxy('edit', fn () => $this->bridge->notify());
    }

    /**
     * Commit (always) and push (only with a remote). The request carries the commit message alone:
     * what may go into the commit is a fact of the commit tree, which the bridge assembles from
     * `source/` + `online/` ({@see \OCA\Pages\Service\ArticleReviewService} for what enters `online/`).
     *
     * A publish is the commit, so a failed push cannot undo it. The answer then says what happened:
     * HTTP 409 with `error: PUSH_REJECTED` (or `PUSH_FAILED`), the ahead count when the remote moved,
     * and the commit that *was* made. The failure is recorded, which is what marks the live rows
     * `pushFailed` until a push goes through again.
     */
    #[NoAdminRequired]
    public function publish(): DataResponse
    {
        [$denied] = $this->siteContext('publish');
        if ($denied !== null) {
            return $denied;
        }
        $message = trim((string)$this->request->getParam('message', ''));
        if ($message === '') {
            $message = sprintf('Update via Nextcloud Pages (%s)', date('Y-m-d H:i'));
        }
        if (mb_strlen($message) > 500) {
            return new DataResponse(['error' => 'BAD_REQUEST', 'message' => $this->l10n->t('Commit message is too long')], 400);
        }

        try {
            $result = $this->bridge->publish($message);
        } catch (\Throwable $e) {
            return $this->translate($e) ?? throw $e;
        }

        $committed = trim((string)($result['commit'] ?? '')) !== '';
        if ($committed) {
            // the publish repository exists from here on: remember it, so the remote/branch lock
            // survives a bridge that cannot be asked (see SiteController::initialised())
            $this->registry->markRepoInitialised();
        }
        $pushError = is_array($result['push_error'] ?? null) ? $result['push_error'] : null;

        $summary = '';
        if ($pushError !== null) {
            $summary = $this->pushFailureMessage($pushError);
            $this->registry->recordPublishPushFailure([
                'code' => (string)($pushError['code'] ?? 'PUSH_FAILED'),
                'ahead' => isset($pushError['ahead']) && $pushError['ahead'] !== null ? (int)$pushError['ahead'] : null,
                'commit' => (string)($result['commit'] ?? ''),
                'message' => $summary,
            ]);
        } else {
            // no remote, or a push that went through: nothing is outstanding on the remote side
            $this->registry->recordPublishPushFailure(null);
        }

        $payload = [
            'ok' => $pushError === null && ($result['ok'] ?? false) === true,
            'result' => $result,
            'commit' => (string)($result['commit'] ?? ''),
            'pushed' => ($result['pushed'] ?? false) === true,
            'remote_configured' => ($result['remote_configured'] ?? false) === true,
        ];
        if ($pushError !== null) {
            $payload['error'] = (string)($pushError['code'] ?? 'PUSH_FAILED');
            $payload['message'] = $summary;
            $payload['ahead'] = isset($pushError['ahead']) && $pushError['ahead'] !== null ? (int)$pushError['ahead'] : null;
            $payload['push_failed'] = true;

            return new DataResponse($payload, 409);
        }

        return new DataResponse($payload);
    }

    /**
     * The one sentence a failed push is reported with.
     *
     * "推送失败" is the only failure wording this app has for a publish, and it appears only here -
     * when a push was really attempted and really failed. A rejected push names the ahead count the
     * bridge measured, because "the remote has new commits" without a number is not actionable.
     *
     * @param array<string,mixed> $pushError the bridge's structured block
     */
    private function pushFailureMessage(array $pushError): string
    {
        $ahead = $pushError['ahead'] ?? null;
        if (($pushError['code'] ?? '') === 'PUSH_REJECTED' && is_int($ahead)) {
            return $this->l10n->t(
                'Push failed: the remote has %s new commit(s); to bring them in use Pull (it replaces the source in Nextcloud)',
                [(string)$ahead],
            );
        }
        $stderr = trim((string)($pushError['stderr'] ?? ''));
        $detail = $stderr === '' ? trim((string)($pushError['hint'] ?? '')) : $stderr;

        return $detail === ''
            ? $this->l10n->t('Push failed')
            : $this->l10n->t('Push failed: %s', [mb_substr($detail, 0, 300)]);
    }

    #[NoAdminRequired]
    public function log(): DataResponse
    {
        $tail = (int)$this->request->getParam('tail', 0);
        return $this->proxy('editOrPublish', fn () => $this->bridge->log(max(0, min($tail, 20000))));
    }

    /**
     * @param string $capability one of edit / publish / editOrPublish, see BaseController::siteContext()
     * @param callable():array<string,mixed> $action
     */
    private function proxy(string $capability, callable $action): DataResponse
    {
        [$denied] = $this->siteContext($capability);
        if ($denied !== null) {
            return $denied;
        }
        try {
            return new DataResponse($action());
        } catch (\Throwable $e) {
            return $this->translate($e) ?? throw $e;
        }
    }
}
