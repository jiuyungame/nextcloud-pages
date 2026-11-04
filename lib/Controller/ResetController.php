<?php

declare(strict_types=1);

namespace OCA\Pages\Controller;

use OCA\Pages\AppInfo\Application;
use OCA\Pages\Service\BridgeClient;
use OCA\Pages\Service\FileService;
use OCA\Pages\Service\PagesReset;
use OCA\Pages\Service\SiteRegistry;
use OCP\AppFramework\Http\DataResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * The one destructive admin action: reset Pages back to a fresh install.
 *
 * It is its own controller because it is not configuration: it is the operation that removes the
 * configuration. The caller has to be an administrator **and** prove the account password in the
 * request body - a session cookie alone must not be able to wipe the instance's Pages state, which is
 * what protects against a stray click on a CSRF-adjacent page. The password never leaves this method:
 * it is checked, not stored, not logged, and no login attempt is recorded (therefore no lockout).
 */
class ResetController extends BaseController
{
    public function __construct(
        IRequest $request,
        IUserSession $userSession,
        IGroupManager $groupManager,
        SiteRegistry $registry,
        FileService $files,
        BridgeClient $bridge,
        IL10N $l10n,
        private PagesReset $reset,
        private IUserManager $userManager,
    ) {
        parent::__construct(Application::APP_ID, $request, $userSession, $groupManager, $registry, $files, $bridge, $l10n);
    }

    /**
     * `POST /api/admin/reset {password}`: verify the password, then run the whole reset.
     *
     * A wrong password answers 401 and runs nothing at all - the reset is not a partial operation that
     * can start before the caller is verified.
     */
    public function reset(): DataResponse
    {
        $denied = $this->requireAdmin();
        if ($denied !== null) {
            return $denied;
        }
        $user = $this->userSession->getUser();
        $password = $this->request->getParam('password', '');
        if ($user === null || !is_string($password) || $password === '') {
            return new DataResponse([
                'error' => 'PASSWORD_REQUIRED',
                'message' => $this->l10n->t('Enter your Nextcloud password; nothing was reset.'),
            ], 401);
        }
        // The concrete manager offers checkPasswordNoLogging(), which verifies without writing a
        // failed-login line; the public interface only has checkPassword(). Both compare the account
        // password (not an app password) and neither runs the brute-force throttle.
        $verified = method_exists($this->userManager, 'checkPasswordNoLogging')
            ? $this->userManager->checkPasswordNoLogging($user->getUID(), $password)
            : $this->userManager->checkPassword($user->getUID(), $password);
        if ($verified === false) {
            return new DataResponse([
                'error' => 'BAD_PASSWORD',
                'message' => $this->l10n->t('Wrong password; nothing was reset.'),
            ], 401);
        }

        try {
            return new DataResponse($this->reset->run());
        } catch (\Throwable $e) {
            // the backup runs first, so a failure here means nothing was cleared; saying which step
            // failed is the only useful answer
            return new DataResponse([
                'error' => 'RESET_FAILED',
                'message' => $this->l10n->t('The reset could not be completed: %s', [$e->getMessage()]),
            ], 500);
        }
    }
}
