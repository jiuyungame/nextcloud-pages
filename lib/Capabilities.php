<?php

declare(strict_types=1);

namespace OCA\Pages;

use OCA\Pages\AppInfo\Application;
use OCA\Pages\Service\SiteRegistry;
use OCP\Capabilities\IPublicCapability;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * The capabilities of this app, published through Nextcloud's own capabilities endpoint
 * (`GET /ocs/v2.php/cloud/capabilities`), so a client can decide what to offer before it calls an
 * endpoint and gets a 403: writing articles ({@see SiteRegistry::getAuthorGroups()}) and the one
 * 审核与发布 grant, which decides about articles **and** publishes them
 * ({@see SiteRegistry::getReviewGroups()}).
 *
 * The answer is per caller, because a group list is: an anonymous request gets every flag `false`
 * (`IPublicCapability` is what makes that answer part of the public endpoint at all), and an
 * administrator gets every flag `true` (administrators always hold every grant). The rules are the
 * same ones the controllers apply - authoring is "everyone" while its group list is empty, and a
 * reviewer/publisher may always author their own posts. Only booleans leave the server, never a group
 * id.
 *
 * `review` and `publish` are the same grant since the merge (see PermissionMigration). Both keys stay
 * in the payload - a client that gates a publish control on `publish` keeps working - and they are
 * computed from the same list, so they cannot disagree.
 */
final class Capabilities implements IPublicCapability
{
    public function __construct(
        private SiteRegistry $registry,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
    ) {
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getCapabilities(): array
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            // nobody is signed in: no group list can match, and authoring needs a session even when
            // its list is empty ("every signed-in user")
            return [Application::APP_ID => ['authoring' => false, 'review' => false, 'publish' => false]];
        }

        $admin = $this->groupManager->isAdmin($user->getUID());
        $groups = $this->groupManager->getUserGroupIds($user);
        $inList = static fn (array $gids): bool => $gids !== [] && array_intersect($groups, $gids) !== [];

        // one grant: the review list is the only stored list that grants publishing
        $review = $admin || $inList($this->registry->getReviewGroups());
        $authorGroups = $this->registry->getAuthorGroups();

        return [
            Application::APP_ID => [
                // no group list is the documented "every signed-in user may author"; a reviewer keeps
                // authoring even when the list names other groups (see BaseController::canAuthor())
                'authoring' => $review || $authorGroups === [] || $inList($authorGroups),
                'review' => $review,
                'publish' => $review,
            ],
        ];
    }
}
