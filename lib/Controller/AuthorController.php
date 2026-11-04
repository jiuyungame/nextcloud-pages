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
 * The author space as the admin panel sees it: one row per participating user, saying whether the area
 * is there, how much is in it and what the site still holds from an earlier push.
 *
 * The panel needs the `publish` grant to read this, because the rows describe every author's folder
 * and not only the caller's own - a plain author reads their own state through their own area instead.
 */
class AuthorController extends BaseController
{
    public function __construct(
        IRequest $request,
        IUserSession $userSession,
        IGroupManager $groupManager,
        SiteRegistry $registry,
        FileService $files,
        BridgeClient $bridge,
        IL10N $l10n,
        private AuthorSourcesService $sources,
    ) {
        parent::__construct(Application::APP_ID, $request, $userSession, $groupManager, $registry, $files, $bridge, $l10n);
    }

    /**
     * The author status table: `{"ok": true, "authors": [{uid, displayName, area, posts,
     * missing, unpublished}]}`. `missing` is always `[]`: the drift comparison that used to fill it is
     * gone, and the field stays because the admin table reads it; `unpublished` counts the author's
     * posts the app has not published yet.
     */
    #[NoAdminRequired]
    public function sources(): DataResponse
    {
        [$denied] = $this->siteContext('publish');
        if ($denied !== null) {
            return $denied;
        }
        try {
            return new DataResponse(['ok' => true, 'authors' => $this->sources->status()]);
        } catch (\Throwable $e) {
            return $this->translate($e) ?? throw $e;
        }
    }
}
