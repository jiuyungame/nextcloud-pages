<?php

declare(strict_types=1);

namespace OCA\Pages\AppInfo;

use OCA\Pages\Capabilities;
use OCA\Pages\Service\AuthorContentReset;
use OCA\Pages\Service\AuthorLayoutMigration;
use OCA\Pages\Service\PermissionMigration;
use OCA\Pages\Service\VisibilityMigration;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * App entry point. Deliberately inert: host-side work belongs to pages-bridge, and installing
 * that service is the administrator's job.
 */
class Application extends App implements IBootstrap
{
    public const APP_ID = 'pages';

    /** Navigation entry of the app: every signed-in user sees it, because the preview needs no grant. */
    public const NAV_NAME = 'Pages';
    public const NAV_ROUTE = 'pages.page.index';
    public const NAV_ICON = 'app.svg';
    public const NAV_ORDER = 2;

    public function __construct(array $urlParams = [])
    {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void
    {
        // The app's three grants (author / review / publish) go out through Nextcloud's own
        // capabilities endpoint, so a client can offer the right controls before it calls an endpoint
        // and gets a 403. The class is handed over as a name: the server builds it per request from
        // this app's container, which is where its services live.
        $context->registerCapability(Capabilities::class);
    }

    public function boot(IBootContext $context): void
    {
        // One-shot permission migration: the retired `edit_groups` grant is merged into
        // `publish_groups` (see PermissionMigration). It runs before anything reads a capability, so
        // the very first request after the upgrade is already served with the migrated grants, and it
        // is idempotent (an appconfig flag), which is what lets every request call it.
        $context->injectFn(static function (PermissionMigration $migration): void {
            $migration->run();
        });

        // The author layout change, in the same shape and for the same reason: files move from
        // `Pages/posts/` to `Pages/`, the manifest keys follow them, and everything that reads an
        // author target afterwards needs the new layout to be in place. Its own flag makes it a no-op
        // on every later request.
        $context->injectFn(static function (AuthorLayoutMigration $migration): void {
            $migration->run();
        });

        // The one-shot reset of all author content, after the layout migration (which is what makes the
        // old tree readable in the first place). Author files are pages now, and the old posts are
        // discarded on purpose: the snapshot, the deletion, the manifest cleanup and the flag are
        // described in the service. Its flag makes it a no-op on every later request - the second boot
        // writes neither the configuration nor the manifest.
        $context->injectFn(static function (AuthorContentReset $reset): void {
            $reset->run();
        });

        // The one-shot move to the three-state visibility model, last: it maps what the migrations above
        // left behind (old state values, `reviewedBy`, the retired request marks) and maps the article
        // table with it. Its own flag makes it a no-op on every later request, which is what keeps the
        // second start byte-identical (see VisibilityMigration).
        $context->injectFn(static function (VisibilityMigration $migration): void {
            $migration->run();
        });

        // The entry is registered at runtime instead of in info.xml: the preview is open to every
        // signed-in user, and a runtime entry can be skipped for anonymous requests (there is no
        // menu to fill in then).
        //
        // Everything must arrive resolved. Nextcloud only expands `route` and a bare `icon` file
        // name for entries declared in info.xml; an array handed to INavigationManager::add() is
        // stored as it is, and the app menu would render a tile with no link and no icon.
        $context->injectFn(static function (
            INavigationManager $navigation,
            IUserSession $userSession,
            IURLGenerator $url,
        ): void {
            if (!$userSession->isLoggedIn()) {
                return;
            }
            $navigation->add([
                'id' => self::APP_ID,
                'name' => self::NAV_NAME,
                'href' => $url->linkToRoute(self::NAV_ROUTE),
                'icon' => $url->imagePath(self::APP_ID, self::NAV_ICON),
                'order' => self::NAV_ORDER,
                'type' => 'link',
            ]);
        });
    }
}
