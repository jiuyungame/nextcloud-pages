<?php

declare(strict_types=1);

namespace OCA\Pages\Controller;

use OCA\Pages\AppInfo\Application;
use OCA\Pages\Service\AuthorLayoutMigration;
use OCA\Pages\Service\BridgeClient;
use OCA\Pages\Service\BridgeParams;
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
     * @return array<string,mixed>
     */
    private function writeResult(): array
    {
        return [
            'ok' => true,
            'config' => $this->snapshot(),
            'revision' => $this->registry->getRevision(),
            'pushed_revision' => $this->registry->getPushedRevision(),
            'has_unpushed' => $this->registry->hasUnpushedChanges(),
        ];
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
     * @param mixed $authorGroups groups allowed to author (absent/null = unchanged, empty list = everyone)
     */
    public function update(
        string $bridgeUrl = '',
        string $sharedSecret = '',
        string $signingSecret = '',
        mixed $bridgeGlobal = null,
        mixed $reviewGroups = null,
        mixed $authorRoot = null,
        mixed $authorGroups = null,
    ): DataResponse {
        $denied = $this->requireAdmin();
        if ($denied !== null) {
            return $denied;
        }

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
            // review grant (审核与发布) by the migration, and a form that still posts either one (a
            // cached admin page) changes no capability by doing so - the keys are simply ignored.
            if ($reviewGroups !== null) {
                $this->registry->setReviewGroups($this->groupGrant($reviewGroups));
            }
            // the author content directory: one site-relative folder name, never empty, validated by
            // the registry (a typo is a 400, not a second layout)
            if ($authorRoot !== null) {
                if (!is_string($authorRoot)) {
                    throw new \InvalidArgumentException(
                        $this->l10n->t('The author content directory must be a single folder name.'),
                    );
                }
                $before = $this->registry->getAuthorRoot();
                $this->registry->setAuthorRoot($authorRoot);
                $after = $this->registry->getAuthorRoot();
                if ($after !== $before) {
                    // every approved file moves with the directory: its manifest key is the site path,
                    // and a decision that stayed on the old key would read as a draft and be dropped
                    // from the mirror. The old targets stay in the pushed list, so the mirror deletes
                    // those copies on the next push.
                    \OCP\Server::get(AuthorLayoutMigration::class)->rekeyAuthorRoot($before, $after);
                }
            }
            // an empty authoring grant means "every signed-in user", unlike edit/publish above: the
            // two lists read the same way in the request, but the absence of a restriction is the
            // documented default here, so an empty list is a value and not a revocation
            if ($authorGroups !== null) {
                $this->registry->setAuthorGroups($this->groupGrant($authorGroups));
            }
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => 'BAD_REQUEST', 'message' => $e->getMessage()], 400);
        }

        return new DataResponse($this->writeResult());
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
            // (`publish_groups` and `edit_groups` are retired compatibility reads - the migration
            // cleared them, and nothing gates on them any more)
            'author_groups' => $this->registry->getAuthorGroups(),
            'review_groups' => $this->registry->getReviewGroups(),
            'publish_groups' => $this->registry->getPublishGroups(),
            'edit_groups' => $this->registry->getEditGroups(),
            // the author space: the site directory every author's tree lands in (the area folder
            // itself is fixed at `Pages`, in each author's own files)
            'author_root' => $this->registry->getAuthorRoot(),
            'author_area' => SiteRegistry::AUTHOR_AREA,
            'protocol' => BridgeClient::PROTOCOL,
            'sites' => count($this->registry->getSites()),
            'revision' => $this->registry->getRevision(),
            'pushed_revision' => $this->registry->getPushedRevision(),
            'has_unpushed' => $this->registry->hasUnpushedChanges(),
        ];
    }
}
