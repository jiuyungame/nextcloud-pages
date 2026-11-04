<?php

declare(strict_types=1);

namespace OCA\Pages\Controller;

use OCA\Pages\AppInfo\Application;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Util;

/**
 * The app's one page and its shell. The shell trims what a non-administrator receives, while every
 * action behind it is gated per endpoint (see BaseController).
 */
class PageController extends BaseController
{
    public function __construct(
        IRequest $request,
        IUserSession $userSession,
        IGroupManager $groupManager,
        \OCA\Pages\Service\SiteRegistry $registry,
        \OCA\Pages\Service\FileService $files,
        \OCA\Pages\Service\BridgeClient $bridge,
        IL10N $l10n,
    ) {
        parent::__construct(Application::APP_ID, $request, $userSession, $groupManager, $registry, $files, $bridge, $l10n);
    }

    /**
     * The **one** page: the caller's own articles, the visibility requests, reading, and the publish
     * control for whoever holds the review-and-publish grant.
     *
     * #[NoCSRFRequired] is required: a bare GET from the app menu carries no requesttoken.
     * #[NoAdminRequired] because the shell itself needs no capability: the preview is open to every
     * signed-in user, and the controls inside it are gated per element (and per endpoint server-side).
     */
    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function index(): TemplateResponse
    {
        return $this->shell();
    }

    private function shell(): TemplateResponse
    {
        // pages-boot registers the error handler before pages-main loads
        Util::addScript(Application::APP_ID, 'pages-boot');
        Util::addScript(Application::APP_ID, 'pages-main');
        Util::addStyle(Application::APP_ID, 'pages-main');

        // every signed-in user gets the shell (the preview needs no grant), but the state is trimmed
        // for non-administrators: the page source is readable, so it must not carry the repository,
        // the paths, the build recipe or the bridge connection details
        $isAdmin = $this->isAdmin();
        $site = $this->registry->getSite();
        $identity = [
            // who is asking: the front end needs it to tell "my own entry" from somebody else's so a
            // row can say whose it is (the decision rules do not look at the owner: a reviewer may
            // decide about any entry, their own included, and `decidedBy` records who answered)
            'uid' => (string)($this->userSession->getUser()?->getUID() ?? ''),
            // the capabilities the front end gates on; `canEdit` and `canPublish` are kept as the names
            // the pages already use, and both are the review grant now (审核与发布, see
            // BaseController::canEdit()), so the shell's shape does not change with the merge
            'canAuthor' => $this->canAuthor(),
            'canReview' => $this->canReview(),
            'canEdit' => $this->canEdit(),
            'canPublish' => $this->canReview(),
            'isAdmin' => $isAdmin,
        ];

        if (!$isAdmin) {
            // `site` is null until the app is configured; the template turns that into an empty object
            return $this->template('index', [
                ...$identity,
                'site' => $site === null ? null : $this->registry->trimSite($site),
            ]);
        }

        return $this->template('index', [
            ...$identity,
            'site' => $site,
            'revision' => $this->registry->getRevision(),
            'pushedRevision' => $this->registry->getPushedRevision(),
            'bridgeUrl' => $this->registry->getBridgeUrl(),
            'connected' => $this->registry->isConnected(),
            'protocol' => \OCA\Pages\Service\BridgeClient::PROTOCOL,
        ]);
    }
}
