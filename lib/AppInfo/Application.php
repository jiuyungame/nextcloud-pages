<?php

declare(strict_types=1);

namespace OCA\Pages\AppInfo;

use OCA\Pages\Capabilities;
use OCA\Pages\Notification\Notifier;
use OCA\Pages\Service\AuthorDeltaService;
use OCA\Pages\Service\BridgeClient;
use OCA\Pages\Service\DeltaQueue;
use OCA\Pages\Service\SiteRegistry;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\User\Events\BeforeUserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * App entry point. Register wires the capabilities and the notifier; boot wires the author-content
 * file events, replays the queued author deltas and runs the one-shot overwrite. Host-side work (git,
 * the site build) belongs to pages-bridge, and installing that service is the administrator's job.
 */
class Application extends App implements IBootstrap
{
    public const APP_ID = 'pages';

    /** Navigation entry of the app (declared in info.xml): every signed-in user sees it, because the preview needs no grant. */
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
        // The app's two grants - authoring and review-and-publish (the `publish` key stays as an
        // alias of the review grant, see Capabilities) - go out through Nextcloud's own capabilities
        // endpoint, so a client can offer the right controls before it calls an endpoint and gets a
        // 403. The class is handed over as a name: the server builds it per request from this app's
        // container, which is where its services live.
        $context->registerCapability(Capabilities::class);

        // The rejection notice is the only notification this app produces: the review action is its
        // only producer (ArticleReviewService::decide()), and it is addressed to the entry's author.
        $context->registerNotifierService(Notifier::class);
    }

    public function boot(IBootContext $context): void
    {
        // Author content, steady state: one file event -> one minimal delta to the bridge. Every writer
        // (app saves, Files app, WebDAV, sync clients) fires these; a path outside `Pages/` is ignored.
        // The app's own save also fires, and that is harmless: the delta is a byte copy and the build is
        // debounced.
        //
        // Only the typed node events are registered, and that is deliberate. `appinfo/info.xml` requires
        // Nextcloud 30+, where the server itself derives these events from the legacy `OC_Filesystem`
        // signals: `OC\Files\Node\HookConnector` listens to the same `post_write`/`post_delete`/
        // `post_rename` signals and dispatches `NodeWrittenEvent`/`NodeDeletedEvent`/`NodeRenamedEvent`
        // from them. Connecting to `OC_Hook` *as well* therefore delivered every operation a second
        // time, and the second delivery was the poorer one: the hook path only sent a delta (it has no
        // owner and no node, so it never ran the delete bookkeeping of
        // {@see AuthorDeltaService::deleted()}), and it turned every rename - a renamed file included -
        // into a whole-author `mirror`. The one gap the hooks could not close either is a write that
        // bypasses the filesystem view entirely (`occ`, a direct storage write): that fires neither
        // signal, and the boot-time `overwrite()` below is what repairs it.
        $context->injectFn(static function (IEventDispatcher $dispatcher, AuthorDeltaService $delta): void {
            $dispatcher->addListener(NodeWrittenEvent::class, static fn (NodeWrittenEvent $event) => $delta->written($event->getNode()));
            $dispatcher->addListener(NodeDeletedEvent::class, static fn (NodeDeletedEvent $event) => $delta->deleted($event->getNode()));
            $dispatcher->addListener(NodeRenamedEvent::class, static fn (NodeRenamedEvent $event) => $delta->renamed($event->getSource(), $event->getTarget()));
            // An account that is about to be deleted takes its author content with it. The files still
            // exist at this point (the event is `Before...`), which is why the bookkeeping runs here:
            // every page the site serves under `<author_root>/<uid>/` gets a take-off request with
            // `sourceDeleted: true`, and every candidate still waiting is withdrawn while the account
            // can still name its owner. The deletion itself must never be interrupted by this: the
            // service catches and logs its own failures.
            $dispatcher->addListener(BeforeUserDeletedEvent::class, static function (BeforeUserDeletedEvent $event) use ($delta): void {
                try {
                    $delta->userDeleted($event->getUser()->getUID());
                } catch (\Throwable) {
                    // `userDeleted()` already logs what it can; a user deletion must not fail because
                    // of a bookkeeping file the app cannot write at this moment
                }
            });
        });

        // The deltas the bridge did not take are durable now ({@see DeltaQueue}). Replay them before
        // the one-shot overwrite: each entry is one specific change, and the overwrite is the full
        // repair that follows. The round is bounded on purpose - a boot must not hold the first
        // request open - and an unreachable bridge is detected with one short `/health` probe instead
        // of one timeout per entry. A non-empty queue on a reachable bridge is the only case that
        // does work here; an empty one is a single appconfig read. A failure of the queue itself is
        // logged and never fails the request: the deltas stay queued for the next boot or the command.
        $context->injectFn(static function (DeltaQueue $queue, SiteRegistry $registry, BridgeClient $bridge, LoggerInterface $logger): void {
            try {
                if ($queue->count() === 0 || !$registry->isConnected() || $registry->getSite() === null) {
                    return;
                }
                try {
                    $bridge->ping(3);
                } catch (\Throwable) {
                    // down (or starting): the queue stays for the next request, no per-entry wait
                    return;
                }
                $queue->replay(static fn (array $body, int $timeout): array => $bridge->authorDelta($body, $timeout));
            } catch (\Throwable $e) {
                $logger->warning('pages: the queued author deltas could not be replayed at boot', ['exception' => $e]);
            }
        });

        // After the boot (and the queued deltas above), one complete author overwrite repairs the
        // preview tree's author area (the commit tree's author area is `online/`, which a boot must not
        // touch); its own flag makes it a no-op afterwards. It is retried on the next boot while the
        // bridge is unreachable, and pairing/reset clears the flag.
        $context->injectFn(static function (AuthorDeltaService $delta): void {
            $delta->overwrite();
        });

    }
}
