<?php

declare(strict_types=1);

namespace OCA\Pages\Service;

use OCA\Pages\AppInfo\Application;
use OCA\Pages\Db\Article;
use OCA\Pages\Db\ArticleMapper;
use OCA\Pages\Notification\Notifier;
use OCP\Files\NotPermittedException;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use OCP\Notification\IManager;

/**
 * The visibility workflow, in the directory-derived model.
 *
 * Three facts decide everything, and none of them is stored twice:
 *
 *   `pending/<target>`   an online request: the bytes the bridge froze when the author asked
 *                        (`candidate` op). A reviewer's approval (`publish`) moves them to `online/`.
 *   `downRequest`        an offline request: the author's intention to take the site's version off.
 *                        It is the one request the folders cannot express, so the manifest carries it.
 *   `online/<target>`    the version the site serves.
 *
 * and the three-state value the rest of the app reads is derived: something pending → `requested`,
 * otherwise something online → `external`, otherwise `internal`.
 *
 *   internal ──request(online)──▶ pending/<target> ──approve (publish)──▶ online/<target>
 *       ▲                              │
 *       │                              └──reject / cancel (discard)──▶ back to what it was
 *       │
 *   online ──request(online)──▶ pending + online   (an online page asks for its new content)
 *        │                          ├── approve ──▶ online = the frozen bytes
 *        │                          └── reject  ──▶ pending gone, online kept (old page stays)
 *        │
 *        ├──request(offline)──▶ downRequest + online
 *        │                          ├── approve (take-down) ──▶ nothing (internal)
 *        │                          └── reject ──▶ downRequest gone, online kept (page stays up)
 *        │
 *        └──take-down (a reviewer, nothing pending) ──▶ nothing (internal)
 *
 * The bridge is the other half of every decision and is called **before** anything is recorded: a
 * request freezes the candidate, an approval publishes it, a rejection or a withdrawal discards it, a
 * take-down removes it from the site. A bridge failure therefore leaves the folders, the manifest and
 * the site exactly as they were - the author or reviewer is told the operation failed and may try
 * again. The one exception is the offline request, which has no content and therefore no bridge call:
 * it is an intention until a reviewer acts on it.
 *
 * **A request writes one thing, an approval writes none.** The candidate is the file in `pending/`,
 * the publication is the file in `online/`; recording either a second time is what the old snapshot
 * model did, and the copy is what went stale. The one exception is the candidate's **digest**: the
 * bridge answers the sha256 of the bytes it froze, and the entry carries it (`candidateDigest`, next
 * to the owner) so that the approval can prove it is publishing exactly the bytes the reviewer saw.
 * An offline request, a refusal and a take-down still leave their own record - a down request, the
 * reviewer's note, the reviewer's name.
 *
 * Deleting a file is not a workflow of this class: it is a direct delete (Nextcloud's trash keeps a
 * copy), the pending request about it is withdrawn by the delete path, and the removal of the page
 * from the site is a `downRequest` ({@see ReviewStore::requestTakeDownForDeletedSources()}).
 *
 * Every change goes through the store, which writes the manifest (when there is anything to write)
 * and then syncs `pages_articles`. That table is a cache: the counts and the queue are read from it,
 * while anything that *decides* something asks the folders.
 *
 * A decision is one critical section per article: `request`, `decide`, `takeDown` and `cancel` take
 * an exclusive lock on the target for the whole read - bridge call - manifest write
 * ({@see self::withReviewLock()}), so two reviewers cannot interleave their answers about one page,
 * and a request cannot slip between a reviewer's read and their bridge call. Different articles
 * never wait for each other.
 */
class ArticleReviewService
{
    /** A request older than this is mentioned by the doctor, not failed. */
    public const STALE_REQUEST_DAYS = 14;

    /**
     * The lock one decision about one article holds for its whole read - bridge call - manifest
     * write: two reviewers acting on the same target exclude each other, while different targets
     * never wait for one another.
     */
    private const REVIEW_LOCK_PREFIX = 'pages-review-';

    public function __construct(
        private ArticleMapper $articles,
        private ReviewStore $store,
        private FileService $files,
        private AuthorAreaService $areas,
        private SiteRegistry $registry,
        private ArticleIndexService $index,
        private BridgeClient $bridge,
        private IL10N $l10n,
        private IUserManager $userManager,
        private IManager $notifications,
        private IURLGenerator $url,
        private OrphanTransfer $orphanTransfer,
        private ILockingProvider $locking,
    ) {
    }

    /**
     * The author's request: put this page on the site (`online`), or take it off (`offline`).
     *
     * Both directions start from "nothing is pending": while a request waits, a second one - either
     * direction - is a conflict, because the two together would say the author wants the page on and
     * off the site at the same time. Withdrawing the waiting request is the way to change one's mind.
     *
     * An **online** request asks the bridge to freeze the file's current bytes into `pending/`. Those
     * bytes are the candidate, and an edit after the request is not part of it - a reviewer decides
     * about exactly the content the author asked for. The bridge also answers the sha256 of the frozen
     * bytes, and the entry records it as `candidateDigest`: the approval sends it back, so a candidate
     * the author replaces while the request waits cannot be published by a retried approval. A bridge
     * that refuses (a source that is not there, a broken bridge) throws and nothing is recorded: what
     * the folders hold is what happened.
     *
     * An **offline** request needs a version the site actually serves (`online/`), and has no content
     * of its own, so it is recorded without a bridge call: the entry carries `downRequest` with the
     * moment and `sourceDeleted: false` (the author keeps the file).
     *
     * A caller with the review grant may file it for anybody's page, an author only for their own.
     *
     * The whole body - the conflict check, the bridge call that freezes the candidate, and the
     * manifest write that binds its digest - is one critical section per article
     * ({@see self::withReviewLock()}), so a decision cannot read "nothing pending" while the request
     * is between its bridge call and its write. The offline direction has no bridge call of its own
     * and therefore refuses while the content directories cannot be confirmed
     * ({@see ReviewStore::requireTrustedTrees()}).
     *
     * @param string $intent `online` or `offline`
     * @throws \InvalidArgumentException when the intent is not one of the two, or the target is no article
     * @throws NotPermittedException when it is somebody else's article
     * @throws ConflictException when a request is already waiting, or the direction cannot start here
     * @throws BridgeException when the bridge cannot freeze the candidate
     * @return array{path:string,state:string,intent:string,created:bool,owner:string,candidateDigest?:string,cache_synced:bool,cache_message:string}
     */
    public function request(string $viewerUid, string $target, string $intent, bool $mayDecide): array
    {
        if (!in_array($intent, Article::INTENTS, true)) {
            throw new \InvalidArgumentException($this->l10n->t('A request intent of “online” or “offline” is required'));
        }
        $article = $this->require($target);
        $path = (string)$article->getPath();
        $owner = trim((string)$article->getOwnerUid());
        if ($owner === '') {
            throw new ConflictException($this->l10n->t(
                'This article belongs to the site itself; an editor publishes it with the site.',
            ));
        }
        if ($owner !== $viewerUid && !$mayDecide) {
            throw new NotPermittedException($this->l10n->t('You may only request a change for your own articles.'));
        }

        return $this->withReviewLock($path, function () use ($path, $owner, $intent): array {
            $manifest = $this->store->read();
            $pending = $this->store->isPending($path);
            $downRequest = ReviewStore::downRequestOf($manifest, $path);
            if ($pending || $downRequest !== null) {
                // both directions start from "nothing is pending": the waiting request may be the other
                // one, and either way a second intent on the same page would make the reviewer answer two
                // questions at once
                throw new ConflictException($this->l10n->t(
                    $pending
                        ? 'A request to publish this article is already waiting for a decision; withdraw it first.'
                        : 'A request to take this article off the site is already waiting for a decision; withdraw it first.',
                ), ['pending' => $pending, 'downRequest' => $downRequest !== null]);
            }

            if ($intent === Article::INTENT_ONLINE) {
                // a failure here throws (BridgeException) and nothing below runs: the folders keep saying
                // "nothing is pending", which is what the bridge holds
                $answer = $this->bridge->reviewOp('candidate', $owner, $path);
                $digest = trim((string)($answer['digest'] ?? ''));
                $this->store->refreshTrees();
                // the fingerprint of the frozen bytes, next to `downRequest` in the entry's vocabulary.
                // Written even when the bridge answers none: an empty value clears a digest a previous
                // request left behind, so an old bridge never makes the next approval compare against
                // bytes nobody reviewed.
                $this->store->setFields([$path], ['owner' => $owner, 'candidateDigest' => $digest]);

                return $this->withCacheState([
                    'path' => $path,
                    'state' => Article::STATE_REQUESTED,
                    'intent' => $intent,
                    'created' => true,
                    'owner' => $owner,
                    'candidateDigest' => $digest,
                ]);
            }

            // the offline direction has no bridge call of its own, so the only evidence that the site
            // serves the article is the content directories. When the bridge cannot confirm them the
            // fallback may simply lag behind a published file, and "nothing to take offline" would be
            // a guess: refuse and let the author retry.
            $this->store->requireTrustedTrees();
            if (!$this->store->isLive($path)) {
                throw new ConflictException($this->l10n->t(
                    'The site does not have this article, so there is nothing to take offline.',
                ));
            }
            $this->store->setFields([$path], [
                'owner' => $owner,
                'downRequest' => ['at' => time(), 'sourceDeleted' => false],
                // a new request is not the previous decision: the rejection note and its author go with it
                'note' => '',
                'decidedBy' => '',
            ]);

            return ['path' => $path, 'state' => Article::STATE_REQUESTED, 'intent' => $intent, 'created' => true, 'owner' => $owner];
        });
    }

    /**
     * Withdraw a pending request.
     *
     * A waiting candidate is discarded on the bridge (`pending/<target>` goes, the review tree returns
     * to what `online/` serves) and then re-read, so the derived state is the folder's answer again. An
     * offline request has no content, so clearing the manifest is the whole withdrawal.
     *
     * The author of the article or a holder of the review grant may withdraw it; the same rule as
     * filing it, because a reviewer may have filed it on the author's behalf.
     *
     * @throws \InvalidArgumentException when the target is no article
     * @throws NotPermittedException when the caller is neither the author nor a grant holder
     * @throws ConflictException when no request is waiting, or another decision about the article is in progress
     * @throws BridgeException when the waiting candidate cannot be discarded
     * @return array{path:string,state:string,intent:string,cancelled:bool,owner:string,cache_synced:bool,cache_message:string}
     */
    public function cancel(string $viewerUid, string $target, bool $mayDecide): array
    {
        // the path has to be known before `require()`: a candidate in `pending/` is not an article
        // the index knows, and withdrawing it must not answer 404 just because the row (or the
        // source file) is not there
        $path = $this->path($target);
        $owner = '';
        try {
            $article = $this->require($target);
            $path = (string)$article->getPath();
            $owner = trim((string)$article->getOwnerUid());
        } catch (\InvalidArgumentException $e) {
            // X1: the bridge froze the candidate and the process died before the request wrote the
            // entry (and the article row). The candidate is the only state there is, and the author
            // is named by the path; withdrawing must still work. `NotFoundException` is a subclass
            // of `\InvalidArgumentException`, so both shapes arrive here.
            if ($path === '' || !$this->store->isPending($path)) {
                throw $e;
            }
            $owner = $this->ownerOf([], $path);
        }
        if ($owner !== $viewerUid && !$mayDecide) {
            throw new NotPermittedException($this->l10n->t('You may only withdraw a request about your own articles.'));
        }

        return $this->withReviewLock($path, function () use ($path, $owner): array {
            $manifest = $this->store->read();
            $entry = ReviewStore::entryOf($manifest, $path);
            $pending = $this->store->isPending($path);
            $downRequest = ReviewStore::downRequestOf($manifest, $path);
            if (!$pending && $downRequest === null) {
                throw new ConflictException($this->l10n->t('No request is waiting for a decision on this article.'));
            }
            if ($pending) {
                $this->bridge->reviewOp('discard', $owner, $path);
                $this->store->refreshTrees();
            }
            if ($entry !== []) {
                // the entry's own record of the withdrawn request goes; the owner (and the article) stay
                $this->store->setFields([$path], [
                    'downRequest' => null,
                    'note' => '',
                    'decidedBy' => '',
                    // the candidate, if there was one, was just discarded: no approved bytes are bound
                    'candidateDigest' => '',
                ]);
            }

            return $this->withCacheState([
                'path' => $path,
                'state' => $this->store->isLive($path) ? Article::STATE_EXTERNAL : Article::STATE_INTERNAL,
                'intent' => $pending ? Article::INTENT_ONLINE : Article::INTENT_OFFLINE,
                'cancelled' => true,
                'owner' => $owner,
            ]);
        });
    }

    /**
     * The reviewer's decision about one entry.
     *
     *   approve  accept what the author asked for. A pending **online** request publishes the frozen
     *            candidate (`pending/<target>` → `online/<target>`, and the host trees follow); the
     *            manifest is not touched - the folders now say the page is live - except when the
     *            candidate had no entry at all ({@see self::sourceExists()} and the X1 note below). The
     *            source file has to still exist: a candidate whose file is gone is refused with a 409
     *            (`SOURCE_GONE`) before the bridge is called, because publishing bytes nobody can
     *            maintain is worse than a rejection. A pending **offline**
     *            request takes the served version off the site: the bridge removes `online/<target>`
     *            (and the host copies), and the entry is either forgotten (the author deleted the file)
     *            or left with the request cleared.
     *   reject   answer the request without doing what it asked: an online request's candidate is
     *            discarded (the version the site already serves, if any, is untouched - a refused first
     *            publication leaves the article internal, a refused update keeps the old page). An
     *            offline request whose file is still there is simply cleared (the page stays on the
     *            site, where it was); one whose file the author already **deleted** cannot stay: the
     *            published copy is transferred to the site's orphan owner instead
     *            ({@see OrphanTransfer}), and a failed handover is a conflict that leaves the request
     *            waiting. The note is the reason the author reads.
     *
     * Nothing pending is not a decision this method takes: a reviewer's direct take-down is its own
     * action ({@see self::takeDown()}), so a `decide` with nothing waiting is a conflict.
     *
     * **A pending candidate and a take-off request at the same time are a conflict, not an order of
     * precedence.** The pair is an inconsistent state nothing should produce (the delete path
     * withdraws a candidate before it asks for the take-off, {@see
     * ReviewStore::requestTakeDownForDeletedSources()}), but an older manifest or a discarded
     * candidate that could not be withdrawn may still hold it. Silently letting `pending/` win would
     * publish a version the author has just asked to take off the site - and, for a deleted source,
     * bytes whose file no longer exists. The reviewer has to say which request they are answering:
     * withdrawing the take-off request is the explicit way out.
     *
     * The whole method - the read of what waits, the bridge call and the manifest write - runs under
     * one lock per article ({@see self::withReviewLock()}), so two reviewers cannot answer the same
     * target at once.
     *
     * The bridge is called before anything is recorded, and a failure is re-thrown: the entry keeps
     * waiting, the author or reviewer may retry, and nothing claims an operation the bridge did not
     * carry out.
     *
     * @throws NotFoundException when neither the manifest nor the folders know the article
     * @throws ConflictException when nothing is waiting for a decision, the two requests disagree, or another decision is in progress
     * @throws BridgeException when the bridge cannot carry the decision out
     * @return array{path:string,state:string,decision:string,intent:string,owner:string,decidedBy:string,wasOnSite:bool,sourceDeleted:bool,transferred?:bool,transfer?:array<string,mixed>,forgotten?:bool,cache_synced:bool,cache_message:string}
     */
    public function decide(string $reviewerUid, string $target, string $decision, string $note): array
    {
        if ($decision !== 'approve' && $decision !== 'reject') {
            throw new \InvalidArgumentException($this->l10n->t('A decision of “approve” or “reject” is required'));
        }
        $path = $this->path($target);

        return $this->withReviewLock($path, function () use ($reviewerUid, $path, $decision, $note): array {
            // a decision reads `pending/` and `online/` to know what it is answering: a fallback
            // answer may lag, and acting on it could publish or take down the wrong version
            $this->store->requireTrustedTrees();
            $manifest = $this->store->read();
            $entry = ReviewStore::entryOf($manifest, $path);
            $owner = $this->ownerOf($entry, $path);
            $pending = $this->store->isPending($path);
            $downRequest = ReviewStore::downRequestOf($manifest, $path);
            $wasOnSite = $this->store->isLive($path);
            if (!$pending && $downRequest === null && $entry === []) {
                throw new NotFoundException($this->l10n->t('No such article: %s', [$path]));
            }
            if ($pending && $downRequest !== null) {
                throw new ConflictException($this->l10n->t(
                    'This article has both a waiting publication and a take-off request. Withdraw the take-off request first, then decide.',
                ), ['pending' => true, 'downRequest' => true]);
            }

            if ($pending) {
                if ($entry === []) {
                    // X1: `candidate` writes `pending/` before the request writes the entry, so a
                    // crash (or a timeout) between the two leaves a candidate the manifest does not
                    // know. The owner is still derivable from the path, and writing it now gives every
                    // later step - the decision record, a rejection, the next cancel - the entry it
                    // writes to. It runs before the bridge call on purpose: a refused publish must
                    // still leave that landing point behind.
                    $this->store->setFields([$path], ['owner' => $owner]);
                }
                if ($decision === 'approve') {
                    // the frozen bytes become the version the site serves; the bridge does both halves in
                    // one operation (pending/ → online/ and the same bytes into the two host trees), so the
                    // folders and the site cannot end up disagreeing about it. Nothing is written here:
                    // `online/<target>` is the record. The digest the request recorded travels with the
                    // approval, so the bridge can refuse bytes the author replaced in the meantime.
                    if (!$this->sourceExists($path, $owner)) {
                        // the candidate was frozen from a file that is gone: publishing it would put bytes
                        // nobody can maintain (or edit afterwards) on the site. A rejection can still
                        // discard it, which is why the request is not cleared here.
                        throw new ConflictException(
                            $this->l10n->t('The source file of this candidate no longer exists, so it can only be rejected.'),
                            ['reason' => 'SOURCE_GONE'],
                        );
                    }
                    $digest = trim((string)($entry['candidateDigest'] ?? ''));
                    try {
                        $this->bridge->reviewOp('publish', $owner, $path, $digest === '' ? null : $digest);
                    } catch (BridgeException $e) {
                        if ($e->getBridgeCode() !== 'DIGEST_MISMATCH') {
                            throw $e;
                        }
                        // The candidate changed after the request: `pending/` holds bytes the reviewer
                        // never saw. The candidate is deliberately left in place - the author may still
                        // be waiting on it, and only a reviewer may decide what happens to it - while
                        // the digest that no longer names those bytes is cleared, so a later retry
                        // asks for the request to be filed again instead of publishing blind.
                        if ($entry !== []) {
                            $this->store->setFields([$path], ['candidateDigest' => '']);
                        }
                        throw new ConflictException(
                            $this->l10n->t('The candidate changed after it was requested; ask the author to request the page again.'),
                            [
                                'reason' => 'DIGEST_MISMATCH',
                                'expected' => (string)($e->getDetails()['expected'] ?? ''),
                                'actual' => (string)($e->getDetails()['actual'] ?? ''),
                            ],
                        );
                    }
                    $this->store->refreshTrees();

                    return $this->withCacheState([
                        'path' => $path,
                        'state' => Article::STATE_EXTERNAL,
                        'decision' => $decision,
                        'intent' => Article::INTENT_ONLINE,
                        'owner' => $owner,
                        'decidedBy' => $reviewerUid,
                        'wasOnSite' => $wasOnSite,
                    ]);
                }

                // a refused online request: the frozen copy is thrown away, and the version the site
                // serves (if any) is deliberately not touched - refusing an update must not take the old
                // page down, and refusing a first publication leaves the article internal
                $this->bridge->reviewOp('discard', $owner, $path);
                $this->store->refreshTrees();
                // the reviewer's answer is recorded: which candidate it discarded (the digest goes with
                // the candidate it described) and who said no, with the note the author reads. The
                // entry is always there by now - X1 above wrote one for a candidate the manifest did
                // not know - so the trail has a landing point.
                $this->store->setFields([$path], [
                    'candidateDigest' => '',
                    'decidedBy' => $reviewerUid,
                    'note' => $note,
                ]);
                $this->notifyRejected($owner, $path, $note, Notifier::DIRECTION_ONLINE);

                return $this->withCacheState([
                    'path' => $path,
                    'state' => $this->store->isLive($path) ? Article::STATE_EXTERNAL : Article::STATE_INTERNAL,
                    'decision' => $decision,
                    'intent' => Article::INTENT_ONLINE,
                    'owner' => $owner,
                    'decidedBy' => $reviewerUid,
                    'wasOnSite' => $wasOnSite,
                ]);
            }

            if ($downRequest !== null) {
                // whether the author's file is already gone, read from the filesystem and not from the
                // request's `sourceDeleted` flag. The flag is what the delete path knew when it filed
                // the request; the file may since have been restored from the trash (or deleted after
                // an offline request), and it is the current fact that changes the answer to this
                // request in both directions - a page nobody can edit any more cannot simply be kept
                // online, while a page whose file is back must not be handed to the orphan owner.
                $sourceDeleted = !$this->sourceExists($path, $owner);
                if ($decision === 'approve') {
                    // an approved take-offline removes the page from the site: the bridge deletes
                    // `online/<target>` and the two host copies
                    $this->bridge->reviewOp('take-down', $owner, $path);
                    $this->store->refreshTrees();
                    if ($sourceDeleted) {
                        // the file is gone as well, so there is nothing left to request or decide about
                        $this->store->forgetArticle([$path]);
                    } else {
                        $this->store->setFields([$path], [
                            'downRequest' => null,
                            'decidedBy' => $reviewerUid,
                            'note' => '',
                            // the page is off the site and no candidate waits for this entry
                            'candidateDigest' => '',
                        ]);
                    }

                    return $this->withCacheState([
                        'path' => $path,
                        'state' => Article::STATE_INTERNAL,
                        'decision' => $decision,
                        'intent' => Article::INTENT_OFFLINE,
                        'owner' => $owner,
                        'decidedBy' => $reviewerUid,
                        'wasOnSite' => $wasOnSite,
                        'sourceDeleted' => $sourceDeleted,
                        'forgotten' => $sourceDeleted,
                    ]);
                }

                if ($sourceDeleted) {
                    // Refusing is not "keep the page up" any more: the author deleted the file, so nobody
                    // can maintain it. The published copy is handed over to the site's orphan owner
                    // instead - it becomes the site's own file, the old address leaves the site, and the
                    // entry is forgotten by the handover.
                    try {
                        $transfer = $this->orphanTransfer->transfer($path);
                    } catch (ConflictException $e) {
                        throw $e;
                    } catch (\Throwable $e) {
                        throw new ConflictException($e->getMessage(), [
                            'transfer_failed' => true,
                            'reason' => $e instanceof BridgeException ? $e->getBridgeCode() : 'ERROR',
                        ]);
                    }
                    $this->notifyRejected($owner, $path, $note, Notifier::DIRECTION_OFFLINE);

                    return $this->withCacheState([
                        'path' => $path,
                        'state' => Article::STATE_INTERNAL,
                        'decision' => $decision,
                        'intent' => Article::INTENT_OFFLINE,
                        'owner' => $owner,
                        'decidedBy' => $reviewerUid,
                        'wasOnSite' => $wasOnSite,
                        'sourceDeleted' => true,
                        'transferred' => true,
                        'transfer' => $transfer,
                    ]);
                }

                // a refused take-off of a page whose file is still there: the page never left the site, so
                // only the request goes
                $this->store->setFields([$path], ['downRequest' => null, 'decidedBy' => $reviewerUid, 'note' => $note]);
                $this->notifyRejected($owner, $path, $note, Notifier::DIRECTION_OFFLINE);

                return $this->withCacheState([
                    'path' => $path,
                    'state' => $this->store->isLive($path) ? Article::STATE_EXTERNAL : Article::STATE_INTERNAL,
                    'decision' => $decision,
                    'intent' => Article::INTENT_OFFLINE,
                    'owner' => $owner,
                    'decidedBy' => $reviewerUid,
                    'wasOnSite' => $wasOnSite,
                    'sourceDeleted' => false,
                ]);
            }

            throw new ConflictException($this->l10n->t('No request is waiting for a decision on this article.'));
        });
    }

    /**
     * The reviewer's direct take-down: take a page the site serves off it, with nothing pending.
     *
     * It is its own action and not a `reject`, because it answers no request - a reviewer acts on their
     * own judgement, and the author is told afterwards (the note is the reason). The bridge deletes
     * `online/<target>` and the two host copies in one operation. The manifest entry is then either
     * forgotten (the source file is gone too, so there is nothing left to request or decide about) or
     * kept with its owner (the author still has the file, and the entry is where their next request
     * lands); the next publish carries the removal out of the commit because the page is no longer in
     * `online/`.
     *
     * Whether the file is gone is read from the filesystem at decision time, not from the request's
     * `sourceDeleted` flag ({@see self::sourceExists()}): the flag records what the delete path knew,
     * and the file may have been restored since.
     *
     * @throws NotFoundException when neither the folders nor the manifest know the article
     * @throws ConflictException when the site does not have the article, or another decision about it is in progress
     * @throws BridgeException when the bridge cannot carry the take-down out
     * @return array{path:string,state:string,decision:string,intent:string,owner:string,decidedBy:string,wasOnSite:bool,sourceDeleted:bool,forgotten:bool,cache_synced:bool,cache_message:string}
     */
    public function takeDown(string $reviewerUid, string $target, string $note): array
    {
        $path = $this->path($target);

        return $this->withReviewLock($path, function () use ($reviewerUid, $path, $note): array {
            // whether the site serves the page comes from `online/`: the fallback cannot prove it does
            // not, so a take-down waits until the bridge can confirm the state
            $this->store->requireTrustedTrees();
            $manifest = $this->store->read();
            $entry = ReviewStore::entryOf($manifest, $path);
            $owner = $this->ownerOf($entry, $path);
            $downRequest = ReviewStore::downRequestOf($manifest, $path);
            if (!$this->store->isLive($path)) {
                if ($entry === [] && $downRequest === null) {
                    throw new NotFoundException($this->l10n->t('No such article: %s', [$path]));
                }
                throw new ConflictException($this->l10n->t(
                    'The site does not have this article, so there is nothing to take down.',
                ));
            }
            $sourceDeleted = !$this->sourceExists($path, $owner);
            $this->bridge->reviewOp('take-down', $owner, $path);
            $this->store->refreshTrees();
            // A take-offline request may still wait (a take-down answers the queue for this entry).
            // The entry is forgotten only while the source file is gone as well: with the file still
            // there the author can ask for the page again, and the entry (with its owner) is where
            // that request lands - and where a candidate without an entry (X1) gets one.
            if ($sourceDeleted) {
                $this->store->forgetArticle([$path]);
            } else {
                $this->store->setFields([$path], [
                    'owner' => $owner,
                    'downRequest' => null,
                    'decidedBy' => '',
                    'note' => '',
                    'candidateDigest' => '',
                ]);
            }
            $this->notifyRejected($owner, $path, $note, Notifier::DIRECTION_TAKE_DOWN);

            return $this->withCacheState([
                'path' => $path,
                'state' => Article::STATE_INTERNAL,
                'decision' => 'take-down',
                'intent' => '',
                'owner' => $owner,
                'decidedBy' => $reviewerUid,
                'wasOnSite' => true,
                'sourceDeleted' => $sourceDeleted,
                'forgotten' => $sourceDeleted,
            ]);
        });
    }

    /**
     * Run one review action under the exclusive lock of its target.
     *
     * The lock is what makes "read what is pending, call the bridge, write the manifest" one step for
     * one article. Without it a take-down and an approval could both read the same state and both
     * succeed: the one whose bridge call landed last won the folders, while the other still wrote its
     * own answer - a page could be served again with no entry saying so, after both reviewers were
     * told the page was off the site.
     *
     * It is the same provider the manifest write takes ({@see ReviewStore::mutate()}), under a key per
     * article, so two articles never wait for each other while the same article is decided once. The
     * lock is not recursive and nothing inside may take it again.
     *
     * @template T
     * @param callable():T $action
     * @return T
     * @throws ConflictException when another decision about the article holds the lock
     */
    private function withReviewLock(string $path, callable $action): mixed
    {
        $key = self::REVIEW_LOCK_PREFIX . ltrim($path, '/');
        try {
            $this->locking->acquireLock($key, ILockingProvider::LOCK_EXCLUSIVE);
        } catch (LockedException) {
            throw new ConflictException(
                $this->l10n->t('Another decision about this article is in progress. Please try again.'),
                ['locked' => $key],
            );
        }
        try {
            return $action();
        } finally {
            $this->locking->releaseLock($key, ILockingProvider::LOCK_EXCLUSIVE);
        }
    }

    /**
     * The answer of a review action with the state of the cache behind it.
     *
     * The store writes the manifest first and syncs the article cache after it, and a sync that fails
     * does not fail the action: the decision the folders hold is already in force, so answering 500
     * would make the caller retry something that already happened.
     *
     * @param array<string,mixed> $answer
     * @return array<string,mixed>
     */
    private function withCacheState(array $answer): array
    {
        $error = $this->store->lastCacheSyncFailed();

        return [...$answer, 'cache_synced' => $error === null, 'cache_message' => $error ?? ''];
    }

    /**
     * Tell the author their request was rejected. A notification must never fail a decision.
     *
     * @param string $direction one of {@see Notifier::DIRECTION_ONLINE}, {@see Notifier::DIRECTION_OFFLINE}
     *                          or {@see Notifier::DIRECTION_TAKE_DOWN}
     */
    private function notifyRejected(string $owner, string $path, string $note, string $direction): void
    {
        if ($owner === '') {
            // a skeleton post of the site itself has no author to tell
            return;
        }
        try {
            // The notification API caps the object id at 64 bytes; a site target is a full path and
            // easily longer, so a digest stands in for the long ones.
            $objectId = strlen($path) > 64 ? hash('sha256', $path) : $path;
            $notification = $this->notifications->createNotification();
            $notification
                ->setApp(Application::APP_ID)
                ->setUser($owner)
                ->setDateTime(new \DateTime())
                ->setObject('article', $objectId)
                ->setSubject(Notifier::SUBJECT_REJECTED, [
                    'name' => basename($path),
                    'note' => $note,
                    'direction' => $direction,
                ])
                ->setLink($this->url->linkToRouteAbsolute('pages.page.index'));
            $this->notifications->notify($notification);
        } catch (\Throwable $e) {
            \OCP\Server::get(\Psr\Log\LoggerInterface::class)->warning(
                'Pages: could not notify the author of the review decision.',
                ['exception' => $e, 'path' => $path, 'direction' => $direction],
            );
        }
    }

    /**
     * How many **author** articles sit in each state, for the doctor and the admin panel.
     *
     * The state is the derived one ({@see ReviewStore::stateIn()}): a down request means `requested`,
     * otherwise the article is `external` exactly while `online/` holds it. The site's own files have
     * no owner and never enter the visibility workflow; a row of an account that is gone is left out.
     * A row whose file is gone is left out **unless a request is pending for it**: the scan still lists
     * such an entry (the delete paths leave its take-off request behind), so counting it is what keeps
     * the numbers in step with the panel.
     *
     * @return array<string,int>
     */
    public function counts(): array
    {
        $counts = array_fill_keys(Article::STATES, 0);
        try {
            $manifest = $this->store->read();
            foreach ($this->articles->findAll() as $row) {
                if (trim((string)$row->getOwnerUid()) === '') {
                    continue;
                }
                if (!$this->liveRow($row)) {
                    continue;
                }
                $state = $this->store->stateIn($manifest, (string)$row->getPath());
                if ($state !== Article::STATE_REQUESTED && !$this->rowFileExists($row)) {
                    continue;
                }
                $counts[$state] = ($counts[$state] ?? 0) + 1;
            }
        } catch (\Throwable) {
            return $counts;
        }

        return $counts;
    }

    /**
     * The requests nobody has looked at for a long time, for the doctor's warning.
     *
     * The moment a request was filed is the cache's `submitted_at` column: an offline request carries
     * its own moment in the manifest, while a candidate's moment is the `pending/` file's mtime, which
     * the store writes into the same column on the next sync. A row of the cache that drifted is
     * repaired by the next review action, so this list is as current as the table it reads.
     *
     * @return list<string> target paths
     */
    public function staleRequests(): array
    {
        $limit = time() - self::STALE_REQUEST_DAYS * 86400;
        $out = [];
        try {
            foreach ($this->articles->findByState(Article::STATE_REQUESTED) as $row) {
                if (!$this->liveRow($row)) {
                    continue;
                }
                if ((int)$row->getSubmittedAt() > 0 && (int)$row->getSubmittedAt() < $limit) {
                    $out[] = (string)$row->getPath();
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return $out;
    }

    /**
     * Whether the file behind a row is still there: an author's post in their own folder, a site-owned
     * post in the site folder.
     */
    private function rowFileExists(Article $row): bool
    {
        $path = ltrim((string)$row->getPath(), '/');
        if ($path === '') {
            return false;
        }

        return $this->sourceExists($path, trim((string)$row->getOwnerUid()));
    }

    /** Whether an article still belongs to somebody: the site itself, or an account that exists. */
    private function liveRow(Article $row): bool
    {
        $owner = trim((string)$row->getOwnerUid());
        if ($owner === '') {
            return true;
        }
        try {
            return $this->userManager->get($owner) !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Whether a site path still has a file behind it: an author's path in their own folder, a path of
     * the site itself in the site folder.
     *
     * This is the **current** fact, read through the Files API and never from the article cache or
     * from a `sourceDeleted` flag a request recorded: a file deleted after an offline request, or
     * restored from the trash after one, has to change the decision, and only the filesystem knows
     * that. A path whose account is gone answers `false` rather than throwing: it is simply not
     * publishable.
     *
     * @param string $path the site target
     * @param string $owner the article's owner, '' for a post of the site itself
     */
    private function sourceExists(string $path, string $owner): bool
    {
        $path = ltrim(trim($path), '/');
        $owner = trim($owner);
        if ($path === '') {
            return false;
        }
        try {
            if ($owner === '') {
                $site = $this->registry->getSite();
                $storageUser = trim((string)($site['nc_user'] ?? ''));
                $ncPath = trim((string)($site['nc_path'] ?? ''));
                if ($storageUser === '' || $ncPath === '') {
                    return false;
                }

                return $this->files->fileExists($storageUser, rtrim($ncPath, '/') . '/' . SiteTrees::SOURCE, $path);
            }
            $resolved = $this->areas->resolve($owner, $path);
            if (!$resolved['ok']) {
                return false;
            }

            return $this->files->fileExists($owner, $this->areas->area(), (string)$resolved['file']);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The article row behind a target, or an exception. Every entry point goes through here, so a
     * target that the app does not know cannot be requested or withdrawn.
     */
    private function require(string $target): Article
    {
        $path = $this->path($target);
        if ($path === '') {
            throw new \InvalidArgumentException($this->l10n->t('An article path is required'));
        }
        $article = $this->articles->findByPath($path);
        if ($article === null) {
            $this->registerMissing($path);
            $article = $this->articles->findByPath($path);
        }
        if ($article === null) {
            throw new \InvalidArgumentException($this->l10n->t('No such article: %s', [$target]));
        }

        return $article;
    }

    /**
     * Creates the cache row for an entry that has none yet. Only a file that really exists in an
     * author's area is registered: a target naming nothing must stay unknown.
     */
    private function registerMissing(string $path): void
    {
        $target = ltrim($path, '/');
        $uid = $this->areas->uidOfTarget($target);
        if ($uid === null) {
            return;
        }
        $resolved = $this->areas->resolve($uid, $path);
        if (!$resolved['ok'] || $resolved['file'] === '') {
            return;
        }
        try {
            if (!$this->areas->areaExists($uid) || !$this->files->fileExists($uid, $this->areas->area(), (string)$resolved['file'])) {
                return;
            }
        } catch (\Throwable) {
            return;
        }
        $this->index->register($uid, $this->areas->area(), (string)$resolved['file'], (string)$resolved['target']);
    }

    /**
     * The owner of an entry: what the manifest recorded, or the uid of the author area the path names.
     * The fallback matters because a candidate in `pending/` is not a manifest entry at all.
     *
     * @param array<string,mixed> $entry
     */
    private function ownerOf(array $entry, string $path): string
    {
        $owner = trim((string)($entry['owner'] ?? ''));
        if ($owner !== '') {
            return $owner;
        }

        return $this->areas->uidOfTarget(ltrim($path, '/')) ?? '';
    }

    /** Normalise whatever the client sent into the stored form (`/<author_root>/<uid>/<file>`). */
    private function path(string $target): string
    {
        $target = trim(str_replace('\\', '/', $target));
        if ($target === '') {
            return '';
        }
        if (str_contains($target, '..')) {
            throw new \InvalidArgumentException($this->l10n->t('An article path must not contain ..'));
        }

        return '/' . ltrim($target, '/');
    }
}
