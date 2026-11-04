<?php

declare(strict_types=1);

namespace OCA\Pages\Notification;

use OCA\Pages\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Renders the app's own notifications.
 *
 * There is one kind: a reviewer decided about an author's page, and the author has no other place to
 * see the reason - the request panel is the reviewer's view, and the author's own list does not show
 * the note. The notification carries the entry's file name, the note and the **direction** the
 * decision answered, so the title says what really happened:
 *
 *   online     the author asked for the page to go live and was refused (it stays internal)
 *   offline    the author asked for the page to come off the site and was refused (it stays up)
 *   take-down  the reviewer took an entry with nothing pending off the site
 *
 * The direction is a parameter of the subject and not a separate subject: notifications stored before
 * it existed keep rendering as an online refusal, which is what they were. The link is the app's front
 * page, because the app has no route that opens one particular article.
 */
final class Notifier implements INotifier
{
    /**
     * The subject of a review decision.
     *
     * The value is the id of every notification this app has ever stored (`publication_rejected`), so
     * it is kept even though the notice now also carries a take-down: changing it would turn the
     * author's older notices into unknown ones.
     */
    public const SUBJECT_REJECTED = 'publication_rejected';

    /** The author asked for the page to go live (`requested` + intent online). */
    public const DIRECTION_ONLINE = 'online';

    /** The author asked for the page to come off the site (`requested` + intent offline). */
    public const DIRECTION_OFFLINE = 'offline';

    /** No request: the reviewer took an `external` entry off the site directly (下线). */
    public const DIRECTION_TAKE_DOWN = 'take-down';

    public function __construct(
        private IFactory $l10nFactory,
        private IURLGenerator $url,
    ) {
    }

    public function getID(): string
    {
        return Application::APP_ID;
    }

    public function getName(): string
    {
        return $this->l10nFactory->get(Application::APP_ID)->t('Pages');
    }

    public function prepare(INotification $notification, string $languageCode): INotification
    {
        if ($notification->getApp() !== Application::APP_ID) {
            throw new UnknownNotificationException('Not a Pages notification');
        }
        $l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
        $params = $notification->getSubjectParameters();
        switch ($notification->getSubject()) {
            case self::SUBJECT_REJECTED:
                $name = trim((string)($params['name'] ?? ''));
                // an older notice has no direction and was always a refused publication request
                $direction = trim((string)($params['direction'] ?? ''));
                if ($direction === self::DIRECTION_OFFLINE) {
                    $notification->setParsedSubject($name === ''
                        ? $l->t('Pages: a request to take an article off the site was rejected')
                        : $l->t('Pages: the request to take “%s” off the site was rejected', [$name]));
                } elseif ($direction === self::DIRECTION_TAKE_DOWN) {
                    $notification->setParsedSubject($name === ''
                        ? $l->t('Pages: an article was taken off the site')
                        : $l->t('Pages: “%s” was taken off the site', [$name]));
                } else {
                    $notification->setParsedSubject($name === ''
                        ? $l->t('Pages: a request to publish was rejected')
                        : $l->t('Pages: the request to publish “%s” was rejected', [$name]));
                }
                $note = trim((string)($params['note'] ?? ''));
                if ($note !== '') {
                    $notification->setParsedMessage($note);
                }
                $notification->setIcon($this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app.svg')));

                return $notification;
        }
        throw new UnknownNotificationException('Unknown Pages notification subject');
    }
}
