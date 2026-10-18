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
 * Build, diff review and publish: thin proxies to the bridge, gated per endpoint by the one
 * review-and-publish grant of the caller (status and log are readable by either name of it).
 * Publishing is the one endpoint that decides *what* is committed (see publish()).
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
        private ArticleReviewService $review,
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
                unset($status['source'], $status['work'], $status['preview'], $status['output_dir']);
                if (is_array($status['last_build'] ?? null)) {
                    unset($status['last_build']['output_dir']);
                }
                // the author sources carry the absolute host path of each author's folder, both in the
                // status itself and again inside the mirror report
                if (array_key_exists('authors', $status)) {
                    $status['authors'] = self::withoutHostPaths($status['authors']);
                }
                if (is_array($status['mirror'] ?? null) && array_key_exists('authors', $status['mirror'])) {
                    $status['mirror']['authors'] = self::withoutHostPaths($status['mirror']['authors']);
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

    #[NoAdminRequired]
    public function build(): DataResponse
    {
        $force = (bool)$this->request->getParam('force', false);
        return $this->proxy('edit', fn () => $this->bridge->build($force));
    }

    /** Lightweight post-save notify: the bridge rebuilds after its debounce window. */
    #[NoAdminRequired]
    public function notify(): DataResponse
    {
        return $this->proxy('edit', fn () => $this->bridge->notify());
    }

    #[NoAdminRequired]
    public function diff(): DataResponse
    {
        return $this->proxy('publish', fn () => $this->bridge->diff());
    }

    /**
     * Commit and push. What goes into the commit is decided here, not by the work copy: an author's
     * article only travels once it was approved, while the site's own files travel for a caller who
     * may edit. Everything else stays uncommitted (and therefore unpublished) even though the preview
     * already shows it.
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
            $changed = $this->changedPaths();
            $classified = $this->review->classify($changed, $this->canEdit());
            $result = $this->bridge->publish($message, $classified['paths']);
        } catch (\Throwable $e) {
            return $this->translate($e) ?? throw $e;
        }

        $outcome = ['published' => [], 'forgotten' => [], 'recorded' => 0];
        $bookkeeping = '';
        if (($result['ok'] ?? false) === true) {
            try {
                $outcome = $this->review->markPublished($classified, (string)($result['commit'] ?? ''));
            } catch (\Throwable $e) {
                // the commit is what matters: a bookkeeping failure must not turn a publish into an
                // error, but it must not disappear either - the reading list depends on this step
                $bookkeeping = $e->getMessage();
            }
        }

        return new DataResponse([
            'ok' => ($result['ok'] ?? false) === true,
            'result' => $result,
            'paths' => $classified['paths'],
            'skipped' => $classified['skipped'],
            'stale' => $classified['stale'] ?? [],
            'published' => $outcome['published'],
            'forgotten' => $outcome['forgotten'],
            'recorded' => $outcome['recorded'],
            'bookkeeping' => $bookkeeping,
        ]);
    }

    /**
     * Every path the work copy has changed, as the bridge reports them. A truncated report is not a
     * problem for the articles (their list comes from the database), but an editor's own file could
     * be missed beyond the cap, so the total travels with the answer.
     *
     * @return list<string>
     */
    private function changedPaths(): array
    {
        $diff = $this->bridge->diff();
        $status = is_array($diff['status'] ?? null) ? $diff['status'] : [];
        $paths = [];
        foreach (['changed', 'untracked'] as $key) {
            foreach ((array)($status[$key] ?? []) as $path) {
                $path = trim((string)$path);
                if ($path !== '') {
                    $paths[$path] = true;
                }
            }
        }

        return array_keys($paths);
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
