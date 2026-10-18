<?php

declare(strict_types=1);

namespace OCA\Pages\Controller;

use OCA\Pages\AppInfo\Application;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Util;

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
        private IURLGenerator $urlGenerator,
    ) {
        parent::__construct(Application::APP_ID, $request, $userSession, $groupManager, $registry, $files, $bridge, $l10n);
    }

    /**
     * The **content** page: articles, review and reading, for every signed-in user.
     *
     * #[NoCSRFRequired] is required: a bare GET from the app menu carries no requesttoken.
     * #[NoAdminRequired] because the shell itself needs no capability: the preview is open to every
     * signed-in user, and the controls inside it are gated per element (and per endpoint server-side).
     */
    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function index(): TemplateResponse
    {
        return $this->shell('content');
    }

    /**
     * The **site source** page: the Jekyll tree, the whole-file editor and build/publish — the work of
     * a publisher, which is the review grant now (审核与发布), and nothing else.
     *
     * Somebody without the grant is sent back to the content page instead of being shown a shell
     * whose every control would be refused by the server.
     */
    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function source(): TemplateResponse|RedirectResponse
    {
        if (!$this->canReview()) {
            return new RedirectResponse($this->urlGenerator->linkToRoute('pages.page.index'));
        }

        return $this->shell('source');
    }

    /**
     * Both pages are the same application with the same state; only the page they start on differs, so
     * the front end never has to guess which half it is.
     */
    private function shell(string $page): TemplateResponse
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
            'page' => $page,
            // who is asking: the front end needs it to tell "my own entry" from somebody else's (a
            // reviewer cannot decide about their own request, so the row has to know whose it is)
            'uid' => (string)($this->userSession->getUser()?->getUID() ?? ''),
            // the capabilities the front end gates on; `canEdit` and `canPublish` are kept as the names
            // the pages already use, and both are the review grant now (审核与发布, see
            // BaseController::canEdit()), so the shell's shape does not change with the merge
            'canAuthor' => $this->canAuthor(),
            'canReview' => $this->canReview(),
            'canEdit' => $this->canEdit(),
            'canPublish' => $this->canReview(),
            'isAdmin' => $isAdmin,
            // the two pages link to each other, so neither has to build a URL by hand
            'contentUrl' => $this->urlGenerator->linkToRoute('pages.page.index'),
            'sourceUrl' => $this->urlGenerator->linkToRoute('pages.page.source'),
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
