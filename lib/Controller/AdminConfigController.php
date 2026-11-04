<?php

declare(strict_types=1);

namespace OCA\Pages\Controller;

use OCA\Pages\AppInfo\Application;
use OCA\Pages\Service\AuthorSourcesService;
use OCA\Pages\Service\BridgeClient;
use OCA\Pages\Service\BridgeParams;
use OCA\Pages\Service\ContentLock;
use OCA\Pages\Service\SiteRegistry;
use OCP\AppFramework\Http\DataResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Read/write endpoints for the admin settings fields, kept separate from the app-facing site API.
 */
class AdminConfigController extends BaseController
{
    public function __construct(
        IRequest $request,
        IUserSession $userSession,
        IGroupManager $groupManager,
        SiteRegistry $registry,
        \OCA\Pages\Service\FileService $files,
        BridgeClient $bridge,
        IL10N $l10n,
        private AuthorSourcesService $authorSources,
        private ContentLock $lock,
    ) {
        parent::__construct(Application::APP_ID, $request, $userSession, $groupManager, $registry, $files, $bridge, $l10n);
    }

    /** Current config; the secrets are returned as fingerprints only, never as plaintext. */
    public function show(): DataResponse
    {
        $denied = $this->requireAdmin();
        if ($denied !== null) {
            return $denied;
        }
        return new DataResponse(['ok' => true, 'config' => $this->snapshot()]);
    }

    /**
     * The response of a write, including the revision the app now holds: the settings form uses it to
     * refresh its "unpushed changes" badge without another round trip.
     *
     * @param array<string,mixed>|null $move the bridge's answer to this call's author-root move, when it
     *        made one. Its `moved` counts and its `work_rebuild` verdict ride along unchanged, so a
     *        scripted caller can tell that the directory moved *and* that the bridge rebuilt its trees;
     *        the settings form reads none of them
     * @return array<string,mixed>
     */
    private function writeResult(?array $move = null): array
    {
        $out = [
            'ok' => true,
            'config' => $this->snapshot(),
            'revision' => $this->registry->getRevision(),
            'pushed_revision' => $this->registry->getPushedRevision(),
            'has_unpushed' => $this->registry->hasUnpushedChanges(),
        ];
        if ($move !== null) {
            foreach (['moved', 'work_rebuild'] as $key) {
                if (array_key_exists($key, $move)) {
                    $out[$key] = $move[$key];
                }
            }
        }

        return $out;
    }

    /**
     * Saves the connection settings; an empty string leaves the stored value unchanged.
     *
     * @param string $bridgeUrl new bridge URL (empty = unchanged)
     * @param string $sharedSecret new shared secret (empty = unchanged)
     * @param string $signingSecret new session signing secret (empty = unchanged)
     * @param mixed $bridgeGlobal global bridge parameters to push (absent/null = unchanged, empty object = clear)
     * @param mixed $reviewGroups groups granted 审核与发布 — reviewing **and** publishing (absent/null = unchanged, empty list = revoke)
     * @param mixed $authorRoot site directory that receives every author's tree (absent/null = unchanged)
     * @param mixed $orphanOwner site directory that receives an article whose author deleted it (absent/null = unchanged)
     * @param mixed $authorGroups groups allowed to author (absent/null = unchanged, empty list = everyone)
     */
    public function update(
        string $bridgeUrl = '',
        string $sharedSecret = '',
        string $signingSecret = '',
        mixed $bridgeGlobal = null,
        mixed $reviewGroups = null,
        mixed $authorRoot = null,
        mixed $orphanOwner = null,
        mixed $authorGroups = null,
    ): DataResponse {
        $denied = $this->requireAdmin();
        if ($denied !== null) {
            return $denied;
        }
        // the write-through flag of the settings page: the configuration and the author content
        // directory are settled in the same call. The directory is stored first (everything below is
        // derived from the stored value) and put back when the bridge refuses the move, so a refusal
        // leaves the previous value in place
        $push = filter_var($this->request->getParam('push', false), FILTER_VALIDATE_BOOLEAN) === true;
        $authorRootBefore = $this->registry->getAuthorRoot();
        $authorRootMoved = false;
        // the bridge's answer to the author-root move: `moved` and `work_rebuild` travel back to the
        // caller from here (null when this call did not move the directory)
        $move = null;

        try {
            if (trim($bridgeUrl) !== '') {
                $this->registry->setBridgeUrl($bridgeUrl);
            }
            if (trim($sharedSecret) !== '') {
                $this->registry->setSharedSecret(trim($sharedSecret));
            }
            if (trim($signingSecret) !== '') {
                $this->registry->setSigningSecret(trim($signingSecret));
            }
            // an object here replaces the whole set of pushed global parameters, which is what lets
            // the form clear a field: clearing it means "back to the bridge's own TOML/default value"
            if (is_array($bridgeGlobal)) {
                $this->registry->setGlobal(BridgeParams::coerceMap($bridgeGlobal));
            } elseif ($bridgeGlobal !== null) {
                throw new \InvalidArgumentException($this->l10n->t('The bridge parameters must be an object'));
            }
            // unlike the secrets above, a key present in the body replaces the grant even when it is
            // empty: "empty secret" means "keep the stored secret", while an empty list is a real
            // value ("no group is granted"), and an absent key is the only way to say "don't touch".
            // `edit_groups` and `publish_groups` are not accepted any more: they were merged into the
            // review grant (审核与发布), and a form that still posts either one (a cached admin page)
            // changes no capability by doing so - the keys are simply ignored.
            if ($reviewGroups !== null) {
                $this->registry->setReviewGroups($this->groupGrant($reviewGroups));
            }
            // The author content directory: one site-relative folder name, never empty, validated by
            // the registry (a typo is a 400, not a second layout). Storing the value is all this
            // block does; the bridge half (its own writer of the running root, then the one-shot
            // overwrite that rewrites the preview tree's author area under the new root) runs at the
            // end of this call, after the push below - so a refused push reverts the stored root
            // before the bridge ever saw it. The manifest is re-keyed only after the bridge has
            // *accepted* the move: its keys are the site paths, and re-keying them while the files
            // are still under the old root would point the decisions at paths nothing is at.
            if ($authorRoot !== null) {
                if (!is_string($authorRoot)) {
                    throw new \InvalidArgumentException(
                        $this->l10n->t('The author content directory must be a single folder name.'),
                    );
                }
                $this->registry->setAuthorRoot($authorRoot);
                $authorRootMoved = $this->registry->getAuthorRoot() !== $authorRootBefore;
            }
            // The orphan owner: the directory that receives an article whose author deleted it. It is
            // validated by the registry (one folder name, and not an existing account - real uids are
            // excluded from the site source mirror, so the transfer would never reach the site), and it
            // does not move any file: only the next transfer reads it.
            if ($orphanOwner !== null) {
                if (!is_string($orphanOwner)) {
                    throw new \InvalidArgumentException(
                        $this->l10n->t('The orphan owner must be a single folder name (letters, digits, dot, underscore, hyphen; no slash).'),
                    );
                }
                $this->registry->setOrphanOwner($orphanOwner);
            }
            // an empty authoring grant means "every signed-in user", unlike edit/publish above: the
            // two lists read the same way in the request, but the absence of a restriction is the
            // documented default here, so an empty list is a value and not a revocation
            if ($authorGroups !== null) {
                $this->registry->setAuthorGroups($this->groupGrant($authorGroups));
            }
            if ($push) {
                // the same push the settings page's write-through uses: the site configuration, in
                // one call. A failure puts the directory back, so the app never holds a value the
                // bridge refused (the manifest keys are derived from it, which is why the store comes
                // first).
                try {
                    $this->authorSources->pushWithAuthors($this->bridge);
                } catch (\Throwable $e) {
                    $this->restoreAuthorRoot($authorRootBefore);
                    throw $e;
                }
            }
            if ($authorRootMoved) {
                // The bridge is the one writer of its running author content directory (a config push
                // only carries the value as information), so the move travels through the dedicated
                // channel - after everything else this call changes was accepted. The value was stored
                // first (the push payload and the manifest keys are derived from it), which is why a
                // refused move has to put it back: the bridge keeps its own old root on every failure -
                // a 409 AUTHOR_ROOT_MOVE_FAILED means the files it had already moved were moved back and
                // nothing was adopted there - so the two sides must not end up naming different
                // directories. Only an accepted move may re-key the manifest; a refusal leaves the keys
                // on the directory the files are still under. The move itself carries `online/` and
                // `pending/` to the new prefix and rebuilds the commit and review trees; the one-shot
                // overwrite that follows rewrites the preview tree's author area under the new root
                // (the old root is named as a purge root).
                try {
                    $move = $this->bridge->setAuthorRoot($this->registry->getAuthorRoot(), false);
                } catch (\Throwable $e) {
                    $this->restoreAuthorRoot($authorRootBefore);
                    throw $this->authorRootMoveFailed($e);
                }
                try {
                    // every approved file moves with the directory: its manifest key is the site
                    // path, and a decision that stayed on the old key would read as a draft and be
                    // dropped from the mirror. Author content itself is carried by the file-event
                    // deltas and the one-shot overwrite.
                    \OCP\Server::get(\OCA\Pages\Service\ReviewStore::class)->rekeyAuthorRoot($authorRootBefore, $this->registry->getAuthorRoot());
                } catch (\Throwable $e) {
                    $this->restoreAuthorRoot($authorRootBefore);
                    throw $e;
                }
                $delta = \OCP\Server::get(\OCA\Pages\Service\AuthorDeltaService::class);
                $delta->forgetOverwrite();
                $delta->overwrite([$authorRootBefore]);
            }
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => 'BAD_REQUEST', 'message' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return $this->translate($e) ?? throw $e;
        }

        return new DataResponse($this->writeResult($move));
    }

    /**
     * Put the author content directory back after a call the bridge did not accept.
     *
     * The registry stores the candidate before the bridge is asked (the push payload and the manifest
     * keys are derived from it), so every failure between the store and the end of the move has to undo
     * it. The bridge never adopts a move it could not complete: on a 409 `AUTHOR_ROOT_MOVE_FAILED` it
     * has already put the files it moved back under the old name, so restoring the stored value is what
     * keeps the app and the bridge on the same directory - it is not a second rollback of anything.
     *
     * The manifest keys are re-keyed back as well when a write did happen: the forward change re-keyed
     * them only after the bridge accepted the move, so this is a no-op in the common failure case and
     * a repair if a later step failed after the re-key.
     */
    private function restoreAuthorRoot(string $before): void
    {
        $current = $this->registry->getAuthorRoot();
        if ($current === $before) {
            return;
        }
        $this->registry->setAuthorRoot($before);
        \OCP\Server::get(\OCA\Pages\Service\ReviewStore::class)->rekeyAuthorRoot($current, $before);
    }

    /**
     * Validates one grant payload: a list of ids of groups that exist. A group that does not exist
     * is rejected instead of stored, because a typo would otherwise sit in the config looking like a
     * working grant. The parameter is untyped on purpose: the framework hands the raw body value
     * through, so a wrong shape has to become this 400 instead of a TypeError (HTTP 500).
     *
     * @return list<string>
     */
    private function groupGrant(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException($this->l10n->t(
                'No such group: %s',
                [is_scalar($value) ? (string)$value : gettype($value)],
            ));
        }
        $groups = [];
        foreach ($value as $gid) {
            // a group id is a non-empty string; anything else can never name a group
            $gid = is_string($gid) ? trim($gid) : '';
            if ($gid === '' || !$this->groupManager->groupExists($gid)) {
                throw new \InvalidArgumentException($this->l10n->t('No such group: %s', [$gid]));
            }
            if (!in_array($gid, $groups, true)) {
                $groups[] = $gid;
            }
        }
        return $groups;
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshot(): array
    {
        return [
            'bridge_url' => $this->registry->getBridgeUrl(),
            'connected' => $this->registry->isConnected(),
            'has_shared_secret' => $this->registry->getSharedSecret() !== '',
            'has_signing_secret' => $this->registry->getSigningSecret() !== '',
            'shared_fingerprint' => $this->registry->secretFingerprint(SiteRegistry::KEY_SHARED_SECRET),
            'signing_fingerprint' => $this->registry->secretFingerprint(SiteRegistry::KEY_SIGNING_SECRET),
            // exactly the parameters the administrator set here; the form renders these as the
            // "pushed from the app" set, and an absent key stays on the bridge's own value
            'bridge_global' => (object)$this->registry->getGlobal(),
            // the two grants that grant something: who may author, and who may review **and** publish
            // (`publish_groups` and `edit_groups` are retired compatibility reads - they grant nothing,
            // and nothing gates on them any more)
            'author_groups' => $this->registry->getAuthorGroups(),
            'review_groups' => $this->registry->getReviewGroups(),
            'publish_groups' => $this->registry->getPublishGroups(),
            'edit_groups' => $this->registry->getEditGroups(),
            // the author space: the site directory every author's tree lands in (the area folder
            // itself is fixed at `Pages`, in each author's own files), and the directory that receives
            // an article whose author deleted it (never a real user id: see SiteRegistry)
            'author_root' => $this->registry->getAuthorRoot(),
            'orphan_owner' => $this->registry->getOrphanOwner(),
            'author_area' => SiteRegistry::AUTHOR_AREA,
            'protocol' => BridgeClient::PROTOCOL,
            'sites' => count($this->registry->getSites()),
            'revision' => $this->registry->getRevision(),
            'pushed_revision' => $this->registry->getPushedRevision(),
            'has_unpushed' => $this->registry->hasUnpushedChanges(),
        ];
    }
}
