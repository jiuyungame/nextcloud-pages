<?php

declare(strict_types=1);

namespace OCA\Pages\Service;

use OCP\Files\Node;
use OCP\Files\Folder;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Author content, steady state: one file event -> one minimal delta to the bridge.
 *
 * The app no longer pushes a target list at all (that cache produced the "removed[0] is not an allowed
 * target" failure and is gone). Every writer - app saves, the Files app, WebDAV, sync clients - fires a
 * node event; a path under `<user>/files/Pages/**` becomes `{uid, op, target}` for the bridge, which
 * copies that one file into the preview tree and triggers the usual debounced build. Nothing in this
 * steady state writes the commit tree: that is the review half (`candidate` + `publish`, `take-down`,
 * {@see ArticleReviewService}). A folder rename sends `{uid, op: "mirror"}` instead, because every
 * descendant would be one delta.
 *
 * An app save also fires the event, exactly like any other writer: the delta is a byte copy and the
 * build is debounced, so no self-write flag is needed.
 *
 * `overwrite()` is the one-shot boot repair: after the app boots (and the bridge answers) the complete
 * author state replaces the preview tree's author area, healing anything the events missed. The commit
 * tree is not touched: its author area is `online/`, which no author action can write.
 */
final class AuthorDeltaService
{
    private const FLAG = 'author_overwrite_v1';

    public function __construct(
        private SiteRegistry $registry,
        private BridgeClient $bridge,
        private AuthorSourcesService $sources,
        private IConfig $config,
        private LoggerInterface $logger,
        /**
         * The visibility manifest: the authority on which targets are on the site and on which
         * requests are waiting. Only the deletion path reads it - a file that is gone takes its
         * pending request with it, and the entry of a page the site still serves asks for its
         * take-down - the deltas themselves do not.
         */
        private ReviewStore $reviews,
        /**
         * The durable queue of deltas the bridge did not take: a save during a restart is replayed
         * at the next boot or by `occ pages:delta-flush` instead of waiting for the boot overwrite.
         */
        private DeltaQueue $queue,
    ) {
    }

    public function written(Node $node): void
    {
        $this->sendPath($node, 'add');
    }

    public function deleted(Node $node): void
    {
        // The request a deleted file carried goes with it: a pending request about a file that is not
        // there any more is not a decision anybody can take, and the frozen review copy of a candidate
        // would stay in the bridge's review tree forever. Recorded before the removal delta, so a
        // reader of the manifest never sees a pending request for a file the bridge was just told to
        // drop.
        $located = $this->locate($node);
        if ($located !== null) {
            $folder = $node instanceof Folder;
            $this->withdrawPending($located[0], $located[1], $folder);
            // ... and what the manifest has to say about a source that is gone: an entry the site still
            // serves asks to be taken down (the author's own intent, expressed where a reviewer sees
            // it), every other entry goes with the file
            $this->requestTakeDownOfDeleted($located[1]);
        }
        $this->sendPath($node, 'remove');
    }

    /**
     * Records a deleted source in the manifest: a take-down request for every entry the site still
     * serves, and no entry for anything else.
     *
     * The rule is the manifest's, not this service's
     * ({@see ReviewStore::requestTakeDownForDeletedSources()}), and it is the same one the app's own
     * delete applies ({@see \OCA\Pages\Controller\BrowseController::delete()}): a page the site serves
     * has to keep its entry and get a `downRequest` with `sourceDeleted: true`, because a reviewer's
     * take-down is the only thing that clears `online/<target>` - this service only sends a `remove`
     * delta, which drops the preview copy. Keeping the entry without a request would leave the page
     * online with the author's intent unexpressed, and only the reviewer's own take-down (Manage)
     * could ever act on it.
     *
     * A failure is logged, never thrown: this runs inside a file event, the file is already gone, and
     * the next review action rewrites the manifest anyway.
     *
     * A target whose frozen candidate could not be withdrawn is reported by the store instead of
     * throwing ({@see ReviewStore::requestTakeDownForDeletedSources()}): no take-off request was
     * recorded for it, and this is where the event says so - the deletion itself has happened, and
     * the settled entry is left for the next delete of that path or for a retry.
     *
     * @param string $target the deleted file or folder as a site target
     */
    private function requestTakeDownOfDeleted(string $target): void
    {
        $key = ltrim(trim(str_replace('\\', '/', $target)), '/');
        if ($key === '') {
            return;
        }
        try {
            $plan = $this->reviews->requestTakeDownForDeletedSources([$key], time());
            if ($plan['failed'] !== []) {
                $this->logger->warning(
                    'pages: the take-off request of a deleted file was not recorded because its frozen candidate could not be withdrawn',
                    ['target' => $target, 'failed' => $plan['failed']],
                );
            }
        } catch (\Throwable $e) {
            $this->logger->warning('pages: the entry of a deleted file could not be settled', [
                'target' => $target,
                'exception' => $e,
            ]);
        }
    }

    /**
     * Withdraws every pending request about a target the author has just removed, and tells the bridge
     * to drop the frozen candidate of an online request.
     *
     * Called from the two delete paths - the file event ({@see self::deleted()}) and the app's own
     * {@see \OCA\Pages\Controller\BrowseController::delete()} - because both have to leave the same
     * record: a file that is gone cannot be waiting for a decision.
     *
     * The bridge call is best-effort on purpose: the file is already deleted at this point, so a
     * discard that fails must not undo the deletion or fail the request. The `pending/` copy it would
     * have removed is inert once the author deleted the source, and the next candidate for the same
     * target overwrites it. A candidate that **was** discarded loses its `candidateDigest` in the
     * same write, because the frozen bytes it described are gone; a failed discard keeps the digest,
     * so the bytes still waiting stay bound to the request that named them.
     *
     * @param string $uid the author whose area holds the target
     * @param string $target the deleted file or folder as a site target
     * @param bool $folder whether the target was a folder (every entry under it is withdrawn)
     */
    public function withdrawPending(string $uid, string $target, bool $folder = false): void
    {
        $key = ltrim(trim(str_replace('\\', '/', $target)), '/');
        if ($key === '') {
            return;
        }
        try {
            $manifest = $this->reviews->read();
            $candidates = [];
            $pending = [];
            foreach (array_keys($manifest['entries']) as $path) {
                $path = (string)$path;
                if ($path !== $key && !($folder && str_starts_with($path, $key . '/'))) {
                    continue;
                }
                if ($this->reviews->isPending($path)) {
                    $candidates[] = $path;
                }
                if (ReviewStore::downRequestOf($manifest, $path) !== null) {
                    $pending[] = $path;
                }
            }
            // a candidate in pending/ has no manifest entry of its own, so the folder is asked too
            foreach ($this->reviews->pendingTargets() as $path) {
                if ($path === $key || ($folder && str_starts_with($path, $key . '/'))) {
                    if (!in_array($path, $candidates, true)) {
                        $candidates[] = $path;
                    }
                }
            }
            if ($pending === [] && $candidates === []) {
                return;
            }
            $discarded = [];
            foreach ($candidates as $path) {
                try {
                    $this->bridge->reviewOp('discard', $uid, $path);
                    $discarded[] = $path;
                } catch (\Throwable $e) {
                    $this->logger->warning('pages: the frozen candidate of a deleted file could not be discarded', [
                        'target' => $path,
                        'exception' => $e,
                    ]);
                }
            }
            $this->reviews->refreshTrees();
            // one manifest write for the whole folder: the requests are one fact ("these files are
            // gone"), and a write per file would be a read-modify-write cycle per file
            if ($discarded !== []) {
                // the frozen candidate each digest described is gone with the discard; a failed
                // discard keeps its binding, so a later approval still publishes only those bytes
                $this->reviews->setFields($discarded, ['candidateDigest' => '']);
            }
            if ($pending !== []) {
                $this->reviews->setFields($pending, ['downRequest' => null, 'note' => '', 'decidedBy' => '']);
            }
        } catch (\Throwable $e) {
            // the file is already deleted; a manifest that cannot be written at this moment is logged
            // and repaired by the next review action, while an exception would report a deletion that
            // did happen as if it had not
            $this->logger->warning('pages: the pending request of a deleted file could not be withdrawn', [
                'target' => $target,
                'exception' => $e,
            ]);
        }
    }

    /**
     * A node rename. A folder sends one `mirror`, because every descendant would otherwise be a
     * delta; a file is a remove plus an add, the same pair of byte copies the bridge already knows.
     *
     * **The old address is a source that is gone, and its bookkeeping follows the same rule as a
     * delete.** A page the site serves under the old target would otherwise keep being served (the
     * `remove` delta only drops the preview copy), with no entry and no request saying it should come
     * down; and a candidate frozen under the old target would wait for a file that is no longer there.
     * So a renamed file records a take-off request on its old target when the site serves it, and a
     * renamed folder hands its whole old prefix to
     * {@see ReviewStore::requestTakeDownForDeletedSources()} - the same one write a deleted folder
     * gets. The new target is deliberately **not** written into the manifest: it starts as a draft,
     * and only an approval publishes it, exactly like a new file.
     *
     * The check looks at both nodes on purpose: a post-rename event describes the source as a
     * non-existing node (the bytes are already at the new path), and Nextcloud builds that node as a
     * *file* even when a folder was renamed (`HookConnector::getNodeForPath()` decides file-or-folder
     * by `is_dir()` on the old path, which is gone). The target exists, so a `Folder` there is the
     * reliable half of the pair.
     */
    public function renamed(Node $source, Node $target): void
    {
        if ($source instanceof Folder || $target instanceof Folder) {
            $from = $this->locate($source);
            if ($from !== null) {
                $this->requestTakeDownOfDeleted($from[1]);
            }
            $uids = [];
            foreach ([$source, $target] as $node) {
                $located = $this->locate($node);
                if ($located !== null) {
                    $uids[$located[0]] = true;
                }
            }
            foreach (array_keys($uids) as $uid) {
                $this->send(['uid' => $uid, 'op' => 'mirror']);
            }

            return;
        }
        $from = $this->locate($source);
        if ($from !== null) {
            $this->recordMovedAway($from[0], $from[1]);
        }
        $this->sendPath($source, 'remove');
        $this->sendPath($target, 'add');
    }

    /**
     * The old address of a renamed file: the same record a delete leaves behind.
     *
     * A candidate still bound to the old address is withdrawn first - the file is at a new path now,
     * so the frozen bytes belong to nobody - and a page the site serves keeps its entry with a
     * `downRequest` whose `sourceDeleted` is true (the old path really is gone). A failure is logged,
     * never thrown: this runs inside a file event, and the rename itself has already happened.
     *
     * @param string $uid the author whose area held the old target
     * @param string $target the old target, as a site target
     */
    private function recordMovedAway(string $uid, string $target): void
    {
        try {
            if ($this->reviews->isPending($target)) {
                $this->withdrawPending($uid, $target);
            }
            if (!$this->reviews->isLive($target)) {
                return;
            }
            $this->reviews->setFields([$target], [
                'owner' => $uid,
                'downRequest' => ['at' => time(), 'sourceDeleted' => true],
                // a new request is not the previous decision: the old note and its author go
                'note' => '',
                'decidedBy' => '',
                'candidateDigest' => '',
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'pages: the old address of a renamed file could not be recorded as a deleted source',
                ['target' => $target, 'exception' => $e],
            );
        }
    }

    /**
     * The author content of an account that is about to be deleted: the same bookkeeping a deleted
     * source gets, for the whole `<author_root>/<uid>/` prefix.
     *
     * A user deletion removes every file the account owns, so every page the site serves under that
     * prefix is a page nobody can maintain any more: its entry has to stay and ask for a take-down
     * (`sourceDeleted: true`), and any candidate still waiting for a decision has to be withdrawn
     * first - both are exactly what {@see ReviewStore::requestTakeDownForDeletedSources()} does. It
     * runs *before* the deletion, so the frozen candidates still have an owner to discard them with.
     *
     * This must never fail the account deletion: a missing site or a bridge that will not answer is
     * logged, and the files are deleted anyway - the next review action or scan repairs what it can.
     */
    public function userDeleted(string $uid): void
    {
        $uid = trim($uid);
        if ($uid === '') {
            return;
        }
        try {
            if ($this->registry->getSite() === null) {
                return;
            }
            $target = $this->registry->getAuthorRoot() . '/' . $uid;
            $plan = $this->reviews->requestTakeDownForDeletedSources([$target], time());
            if ($plan['failed'] !== []) {
                $this->logger->warning(
                    'pages: the take-off request of a deleted account was not recorded for some files because their frozen candidate could not be withdrawn',
                    ['uid' => $uid, 'failed' => $plan['failed']],
                );
            }
        } catch (\Throwable $e) {
            $this->logger->warning('pages: the bookkeeping of a deleted account failed', [
                'uid' => $uid,
                'exception' => $e,
            ]);
        }
    }

    /**
     * One-shot: repair the **preview** tree's author area from the complete scanned state.
     *
     * The commit tree is not touched: its author area is `online/`, the content a reviewer approved,
     * and a boot repair must not be able to put an unreviewed source file live. The bridge accepts the
     * payload once and drops stray files under each author prefix.
     *
     * The flag is set only after the bridge accepted the payload, so a failed overwrite is retried on
     * the next request instead of leaving a half-healed mirror marked as done.
     *
     * `$extraPurge` names additional directory roots the bridge must empty on top of the legacy
     * cache's roots - the one call site is the author content directory move.
     *
     * @param list<string> $extraPurge
     * @return bool whether the complete author state was delivered and accepted
     */
    public function overwrite(array $extraPurge = []): bool
    {
        if ($this->config->getAppValue('pages', self::FLAG, '0') === '1') {
            return true;
        }
        if (!$this->registry->isConnected() || $this->registry->getSite() === null) {
            return false;
        }
        $payload = $this->sources->overwritePayload();
        if ($extraPurge !== []) {
            // one payload, so the bridge's delete guard sees the whole round: the purge roots and
            // the rebuild are a single replacement, not two competing deletions
            $payload['purge'] = array_values(array_unique(array_merge($payload['purge'], $extraPurge)));
        }
        try {
            $this->bridge->authorDelta(['op' => 'overwrite'] + $payload);
        } catch (\Throwable $e) {
            // try again on the next request/boot; nothing is marked done on a failure
            $this->logger->warning('pages: the one-shot author overwrite failed', ['error' => $e->getMessage()]);
            return false;
        }
        $this->config->setAppValue('pages', self::FLAG, '1');
        // the payload just carried the complete current author state, so any delta queued before it
        // is superseded: replaying a stale `remove` after this repair would delete a file the
        // overwrite has just mirrored
        $this->queue->clear();

        return true;
    }

    /** Forget the one-shot flag (pairing/reset): the next boot repairs the fresh state. */
    public function forgetOverwrite(): void
    {
        $this->config->deleteAppValue('pages', self::FLAG);
    }

    private function sendPath(Node $node, string $op): void
    {
        $located = $this->locate($node);
        if ($located === null) {
            return;
        }
        [$uid, $target] = $located;
        $this->send(['uid' => $uid, 'op' => $op, 'target' => $target]);
    }

    /**
     * @return array{0:string,1:string}|null [uid, site-relative target]
     */
    private function locate(Node $node): ?array
    {
        return $this->locatePath($node->getPath());
    }

    /**
     * The one path shape that arrives is a node's absolute path (`/<uid>/files/Pages/…`): the three
     * listeners of {@see \OCA\Pages\AppInfo\Application} hand over `Node` objects, so the shape a
     * legacy filesystem hook used to deliver (a user-relative `files/Pages/…` plus an owner) no longer
     * reaches this service. Anything else is not author content, and an unconfigured site has no
     * author root to build a target from.
     *
     * @return array{0:string,1:string}|null [uid, site-relative target]
     */
    private function locatePath(string $rawPath): ?array
    {
        $path = '/' . ltrim(str_replace('\\', '/', $rawPath), '/');
        if (preg_match('#^/(?<uid>[^/]+)/files/Pages/(?<rel>.+)$#', $path, $m) !== 1) {
            return null;
        }
        $uid = $m['uid'];
        $rel = $m['rel'];
        if ($this->registry->getSite() === null) {
            return null;
        }
        $root = $this->registry->getAuthorRoot();

        return [$uid, $root . '/' . $uid . '/' . $rel];
    }

    /**
     * Sends one delta. Best-effort by design: the steady state must not fail a save because the
     * bridge did not answer.
     *
     * It is not silent any more, though. The client timeout now covers the bridge's lock window
     * ({@see BridgeClient::authorDelta()}), so a failure here is a real outage or a real refusal
     * rather than the expected cost of a build that outlasted 30 seconds - and this line is the one
     * trace that says the boot overwrite had something to heal.
     *
     * A failed `add`/`update`/`remove` is also **queued** ({@see DeltaQueue}): the boot overwrite
     * heals the preview tree only on the next boot, while the queue is replayed at the next boot or
     * by `occ pages:delta-flush`, so a save inside a bridge restart is not lost until then. A delta
     * that goes through settles any older failure for the same target: the file's newest bytes are
     * what the bridge holds now, and a stale entry must not be replayed over them.
     *
     * @param array<string,mixed> $body
     */
    private function send(array $body): void
    {
        $op = (string)($body['op'] ?? '');
        $uid = (string)($body['uid'] ?? '');
        $target = (string)($body['target'] ?? '');
        try {
            $this->bridge->authorDelta($body);
            $this->queue->settle($target);
        } catch (\Throwable $e) {
            // the steady state is best-effort: the event is queued (or dropped when it is not a
            // content op) and replayed later; the save itself is already on disk
            $queued = $this->queue->enqueue($op, $uid, $target);
            $this->logger->warning('pages: the author delta was not delivered to the bridge', [
                'op' => $op,
                'uid' => $uid,
                'target' => $target,
                'queued' => $queued,
                'exception' => $e,
            ]);
        }
    }
}
