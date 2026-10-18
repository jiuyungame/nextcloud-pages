<?php

declare(strict_types=1);

namespace OCA\Pages\Controller;

use OCA\Pages\AppInfo\Application;
use OCA\Pages\Service\AuthorSourcesService;
use OCA\Pages\Service\BridgeClient;
use OCA\Pages\Service\BridgeException;
use OCA\Pages\Service\PairingService;
use OCA\Pages\Service\SiteMigrationService;
use OCA\Pages\Service\SiteRegistry;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Site configuration and bridge connection; the app is the source of truth for the one site.
 */
class SiteController extends BaseController
{
    public function __construct(
        IRequest $request,
        IUserSession $userSession,
        IGroupManager $groupManager,
        SiteRegistry $registry,
        \OCA\Pages\Service\FileService $files,
        BridgeClient $bridge,
        private PairingService $pairing,
        private SiteMigrationService $migration,
        private AuthorSourcesService $authorSources,
        IL10N $l10n,
    ) {
        parent::__construct(Application::APP_ID, $request, $userSession, $groupManager, $registry, $files, $bridge, $l10n);
    }

    /**
     * The one site. [#NoAdminRequired] because every signed-in user gets the shell: a non-admin is
     * handed exactly {canEdit, canPublish, isAdmin, site} with the site trimmed down to
     * {display, connected, revision}, so nothing about the repository, the paths, the build recipe or
     * the bridge connection leaves the server. `site` is null until the app has been configured.
     *
     * `canEdit` and `canPublish` are the same grant now (审核与发布, see BaseController::canEdit()); the
     * two keys stay so a client that reads either name keeps working.
     */
    #[NoAdminRequired]
    public function list(): DataResponse
    {
        $isAdmin = $this->isAdmin();
        $identity = [
            'canEdit' => $this->canEdit(),
            'canPublish' => $this->canReview(),
            'isAdmin' => $isAdmin,
            // the one public fact of the author layout: where an author's own tree lands in the site.
            // The shell needs it to build the target of a brand new file before the server sees it.
            'author_root' => $this->registry->getAuthorRoot(),
        ];
        $site = $this->registry->getSite();
        if (!$isAdmin) {
            return new DataResponse([
                ...$identity,
                'site' => $site === null ? null : $this->registry->trimSite($site),
            ]);
        }
        return new DataResponse([
            ...$identity,
            'site' => $site,
            'revision' => $this->registry->getRevision(),
            'pushed_revision' => $this->registry->getPushedRevision(),
            'has_unpushed_changes' => $this->registry->hasUnpushedChanges(),
            'bridge_url' => $this->registry->getBridgeUrl(),
            'connected' => $this->registry->isConnected(),
            'protocol_expected' => BridgeClient::PROTOCOL,
        ]);
    }

    /**
     * Read-only connectivity and version probe: failures still return HTTP 200 with the error in
     * the payload, so the settings page can render them as a notice. The environment report rides
     * along because the settings form needs the effective values and their sources to label every
     * field; it is trimmed to the few keys the form uses, and never carries a secret.
     */
    public function bridgeHealth(): DataResponse
    {
        $denied = $this->requireAdmin();
        if ($denied !== null) {
            return $denied;
        }
        return new DataResponse($this->probe());
    }

    /**
     * The bare liveness probe: no session, no CSRF token, no signature, no side effect.
     *
     * It answers the one question a monitor, a systemd `ExecStartPost` or a curl one-liner can ask
     * without a credential - "is the companion service there, and which version speaks which
     * protocol" - by proxying the bridge's own read-only `/health`. A bridge that is not there is a
     * `503` with `ok:false` and the error code, because that is what a probe is for; the body stays
     * JSON in both cases, so a caller never has to parse an HTML error page.
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function ping(): DataResponse
    {
        try {
            $answer = $this->bridge->ping();
        } catch (\Throwable $e) {
            $response = new DataResponse([
                'ok' => false,
                'version' => '',
                'protocol' => 0,
                'error' => $e instanceof BridgeException ? $e->getBridgeCode() : 'ERROR',
                'message' => $e->getMessage(),
            ], Http::STATUS_SERVICE_UNAVAILABLE);
            if ($e instanceof BridgeException && $e->isRetryable()) {
                // the probe says the same "later" an interactive caller is told
                $response->addHeader('Retry-After', (string)self::RETRY_AFTER_UNREACHABLE);
            }

            return $response;
        }

        return new DataResponse($answer);
    }

    /**
     * Retries the push a save could not deliver, addressed by nothing: the author is the caller.
     *
     * An author's save writes the file and then pushes the author sources; when the bridge is down the
     * file is safe, the push is not, and the entry carries a "not pushed · retry" mark in the browser.
     * Clicking it lands here. It runs exactly the push the save path runs ({@see BaseController::syncAuthorSources()}),
     * so there is no second mechanism that could drift: the same payload, the same author grant, and the
     * same answer shape - HTTP 200 with `bridge.pushed`, because "the file is saved and the mirror did
     * not take it" is a state, not an HTTP error.
     *
     * It is idempotent for one author and one target set: pushing the same authors block twice replaces
     * the same configuration with itself and only marks the preview dirty, and no commit happens on this
     * path at all (commits are made by POST /api/site/publish), so repeated clicks cannot create one.
     */
    #[NoAdminRequired]
    public function pushAuthorSources(): DataResponse
    {
        $denied = $this->requireAuthor();
        if ($denied !== null) {
            return $denied;
        }
        $bridge = $this->syncAuthorSources();

        return new DataResponse([
            'ok' => $bridge['pushed'] === true,
            'bridge' => $bridge,
            'revision' => $this->registry->getRevision(),
            'pushed_revision' => $this->registry->getPushedRevision(),
        ]);
    }

    /**
     * Pairs this app with a bridge that has no working configuration yet. Both secrets are generated
     * here and only stored once the bridge confirms them; the data directory is discovered from
     * Nextcloud, so the administrator types nothing but the URL and the one-time code.
     *
     * @param string $url bridge address as typed in the settings form
     * @param string $code the one-time code bridge/install.sh printed
     */
    public function pair(string $url = '', string $code = ''): DataResponse
    {
        $denied = $this->requireAdmin();
        if ($denied !== null) {
            return $denied;
        }
        if (trim($url) === '') {
            return new DataResponse([
                'error' => 'PAIR_FAILED',
                'error_code' => 'NO_URL',
                'message' => $this->l10n->t('The bridge URL is not configured'),
            ], 400);
        }
        try {
            $result = $this->pairing->pair($url, $code);
        } catch (BridgeException $e) {
            // the bridge's own status and code are passed through, so the form can say
            // "wrong code, N attempts left" instead of a generic failure
            $bridgeCode = $e->getBridgeCode();
            return new DataResponse(
                [
                    'error' => 'PAIR_FAILED',
                    'error_code' => $bridgeCode === 'UNPAIRED' ? 'BRIDGE_UNPAIRED' : $bridgeCode,
                    'message' => $e->getMessage(),
                    'attemptsLeft' => $e->getDetails()['attempts_left'] ?? null,
                ],
                $this->pairStatus($e),
            );
        } catch (\Throwable $e) {
            return new DataResponse([
                'error' => 'PAIR_FAILED',
                'error_code' => 'ERROR',
                'message' => $e->getMessage(),
            ], 500);
        }
        return new DataResponse([
            'ok' => true,
            'paired' => true,
            'url' => $result['url'],
            'protocol' => $result['protocol'],
            'version' => $result['version'],
            'restartRequired' => $result['restart_required'],
            'fingerprints' => $result['fingerprints'],
            'environment' => $this->environmentSummary(),
        ]);
    }

    public function pushConfig(): DataResponse
    {
        $denied = $this->requireAdmin();
        if ($denied !== null) {
            return $denied;
        }
        // an explicit flag: keep the stored secrets, generate a new pair, or take the two values
        // typed into the advanced fields (the manual fallback)
        $rotate = in_array(strtolower((string)$this->request->getParam('rotate', '')), ['1', 'true', 'yes'], true);
        $typedShared = trim((string)$this->request->getParam('sharedSecret', ''));
        $typedSigning = trim((string)$this->request->getParam('signingSecret', ''));
        if (!$rotate && ($typedShared !== '' || $typedSigning !== '')) {
            // the bridge replaces both keys in one atomic write, so a half-filled pair is refused
            // here instead of being sent (or silently dropped)
            if ($typedShared === '' || $typedSigning === '') {
                return new DataResponse([
                    'error' => 'BAD_REQUEST',
                    'message' => $this->l10n->t('Both secrets are required to replace them'),
                ], 400);
            }
            $secrets = ['shared_secret' => $typedShared, 'session_signing_secret' => $typedSigning];
        } elseif ($rotate) {
            $secrets = [
                'shared_secret' => $this->pairing->newSecret(),
                'session_signing_secret' => $this->pairing->newSecret(),
            ];
        } else {
            $secrets = null;
        }

        $previous = null;
        if ($secrets !== null) {
            // rotation authenticates with the stored secret, and the new pair is written only after
            // the bridge confirms the push: a rejected payload leaves the working secrets in place
            $previous = [
                SiteRegistry::KEY_SHARED_SECRET => $this->registry->getSharedSecret(),
                SiteRegistry::KEY_SIGNING_SECRET => $this->registry->getSigningSecret(),
            ];
            $this->registry->setSharedSecret($secrets['shared_secret']);
            $this->registry->setSigningSecret($secrets['session_signing_secret']);
        }
        // the authors' folders ride along with the site they belong to. `removed` is computed against
        // the last accepted push, so the target list is only stored once the bridge has accepted this
        // one: a failed push leaves the app certain about what the mirror holds, not hopeful
        $authorSources = $this->authorSources->build();
        try {
            $result = $this->bridge->pushConfig([
                ...($secrets === null ? [] : ['secrets' => $secrets]),
                'authors' => $authorSources['authors'],
            ]);
        } catch (\Throwable $e) {
            if ($previous !== null) {
                // put the old pair back: the bridge kept using it, so the app must keep using it too
                $this->registry->setSharedSecret($previous[SiteRegistry::KEY_SHARED_SECRET]);
                $this->registry->setSigningSecret($previous[SiteRegistry::KEY_SIGNING_SECRET]);
            }
            return $this->translate($e) ?? throw $e;
        }
        $this->registry->setAuthorSources($authorSources['targets'], $authorSources['onsite']);
        return new DataResponse([
            'ok' => true,
            'result' => $result,
            'revision' => $this->registry->getRevision(),
            'pushed_revision' => $this->registry->getPushedRevision(),
            'has_unpushed' => $this->registry->hasUnpushedChanges(),
            'restart_required' => (bool)($result['restart_required'] ?? false),
            'rotated' => $secrets !== null,
            'authors' => array_map(
                static fn (array $author): array => [
                    'uid' => (string)($author['uid'] ?? ''),
                    'entries' => count($author['entries'] ?? []),
                    'removed' => count($author['removed'] ?? []),
                ],
                $authorSources['authors'],
            ),
            'fingerprints' => [
                'shared_secret' => $this->registry->secretFingerprint(SiteRegistry::KEY_SHARED_SECRET),
                'session_signing_secret' => $this->registry->secretFingerprint(SiteRegistry::KEY_SIGNING_SECRET),
            ],
            // the bridge reports which revision it now holds, so the push answers the "is everything
            // synced" question directly instead of leaving it to the next page load
            'environment' => $this->environmentSummary(),
        ]);
    }

    /**
     * HTTP status echoed back to the settings form for a failed pairing. 401 and 410 are passed
     * through as they are (wrong code / expired code), an unreachable bridge becomes 502, and every
     * other bridge answer keeps its own status so nothing is reported as a success.
     */
    private function pairStatus(BridgeException $e): int
    {
        $status = $e->getHttpStatus();
        if ($status >= 400 && $status <= 599) {
            return $status;
        }
        return match ($e->getBridgeCode()) {
            'BAD_CODE' => 401,
            'CODE_EXPIRED' => 410,
            'UNREACHABLE' => 502,
            'BAD_URL', 'NO_CODE' => 400,
            default => 502,
        };
    }

    /**
     * Bridge state as the settings form needs it: pairing state and version for the header line,
     * plus the effective configuration and its per-key sources. Failures keep HTTP 200 so the form
     * renders them as a notice, and an unpaired bridge is reported as such rather than as an error.
     *
     * @return array<string,mixed>
     */
    private function probe(): array
    {
        $out = [
            'ok' => true,
            'paired' => $this->registry->isConnected(),
            'bridge_url' => $this->registry->getBridgeUrl(),
            'protocol_expected' => BridgeClient::PROTOCOL,
            'version' => '',
            'health' => null,
            'error' => null,
            'message' => '',
            'details' => [],
        ];
        try {
            $health = $this->bridge->health();
            $out['health'] = $health;
            $out['version'] = (string)($health['version'] ?? '');
            // a bridge that has a URL but no stored secret still answers /health; say so instead of
            // reporting a dead connection, because the fix is "pair it", not "start the service"
            $out['paired'] = ($health['paired'] ?? $out['paired']) !== false && $this->registry->isConnected();
            $out['pairing_required'] = (bool)($health['pairing_required'] ?? false);
        } catch (\Throwable $e) {
            $out['ok'] = false;
            $out['error'] = $e instanceof BridgeException ? $e->getBridgeCode() : 'ERROR';
            $out['message'] = $e->getMessage();
            $out['details'] = $e instanceof BridgeException ? $e->getDetails() : [];
        }
        // only meaningful while paired: /env answers 503 in the UNPAIRED state
        $out['environment'] = $this->registry->isConnected() ? $this->environmentSummary() : null;
        return $out;
    }

    /**
     * The part of `GET /env` the form compares against: effective values, their sources, and the
     * secret fingerprints. Secrets are fingerprints in the bridge response already; the explicit
     * pick keeps any future field of that block out of the browser.
     *
     * @return array<string,mixed>|null
     */
    private function environmentSummary(): ?array
    {
        try {
            $env = $this->bridge->environment();
        } catch (\Throwable) {
            return null;
        }
        $secrets = is_array($env['secrets'] ?? null) ? $env['secrets'] : [];
        return [
            'effective' => is_array($env['effective'] ?? null) ? $env['effective'] : null,
            'sources' => is_array($env['sources'] ?? null) ? $env['sources'] : null,
            'revision' => isset($env['revision']) ? (int)$env['revision'] : null,
            'config_source' => isset($env['config_source']) ? (string)$env['config_source'] : null,
            'secrets' => [
                'shared_secret' => is_array($secrets['shared_secret'] ?? null) ? $secrets['shared_secret'] : null,
                'session_signing_secret' => is_array($secrets['session_signing_secret'] ?? null) ? $secrets['session_signing_secret'] : null,
            ],
        ];
    }

    public function save(): DataResponse
    {
        $denied = $this->requireAdmin();
        if ($denied !== null) {
            return $denied;
        }
        try {
            // the body is the site object itself; a stray id/name/work_path in it is dropped by
            // normalize(), so an older front end cannot write the removed fields back
            $site = $this->registry->setSite($this->withUneditedFields($this->request->getParams()));
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => 'BAD_REQUEST', 'message' => $e->getMessage()], 400);
        }
        // saving only stores the configuration; pushing it to the bridge stays an explicit action
        // (POST /api/bridge/push / occ pages:push), which is what has_unpushed_changes reports on
        return new DataResponse([
            'ok' => true,
            'site' => $site,
            'revision' => $this->registry->getRevision(),
            'has_unpushed_changes' => $this->registry->hasUnpushedChanges(),
        ]);
    }

    /**
     * Keys of the site object the settings page no longer edits: the build recipe and the site-level
     * exclude list moved to the bridge TOML / `occ pages:config` (they duplicate what the bridge owns).
     * The endpoint keeps accepting them - a scripted `PUT /api/site` still sets them - but a form that
     * omits them must not erase what an earlier one stored, which is what normalize() would do with an
     * absent `recipe` (it would fall back to the Jekyll defaults and drop the command).
     *
     * @param array<string,mixed> $params the request body
     * @return array<string,mixed>
     */
    private function withUneditedFields(array $params): array
    {
        $stored = $this->registry->getSite() ?? [];
        foreach (['recipe', 'exclude'] as $key) {
            if (!array_key_exists($key, $params) && array_key_exists($key, $stored)) {
                $params[$key] = $stored[$key];
            }
        }

        return $params;
    }

    /**
     * What saving a different "site storage user" would mean; nothing is written. Administrator only,
     * and deliberately separate from PUT /api/site: the move is a one-shot action of its own and must
     * never happen implicitly inside a save (§14).
     */
    public function preflight(): DataResponse
    {
        $denied = $this->requireAdmin();
        if ($denied !== null) {
            return $denied;
        }
        try {
            return new DataResponse([...$this->migration->preflight($this->targetUser()), 'revision' => $this->registry->getRevision()]);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => 'BAD_REQUEST', 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * Performs the move: copy → verify → config → push → delete old, stopping at the first failure.
     * A failing step is answered as a 200 with `ok: false` and the per-step report, because the form
     * has to show which step stopped and why — that is not an HTTP error but a state to act on.
     */
    public function migrate(): DataResponse
    {
        $denied = $this->requireAdmin();
        if ($denied !== null) {
            return $denied;
        }
        try {
            return new DataResponse($this->migration->migrate($this->targetUser()));
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => 'BAD_REQUEST', 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * The target user from the request body. `nc_user` is the field name the site payload uses and
     * what the settings form sends; `ncUser` is accepted too, so neither spelling is a trap.
     */
    private function targetUser(): string
    {
        $value = $this->request->getParam('nc_user') ?? $this->request->getParam('ncUser', '');

        return is_string($value) ? trim($value) : '';
    }

    /**
     * Bootstraps the working copy (first git pull) through the bridge, the only component that can
     * run git: an empty working copy is really cloned, an empty source is back-filled.
     */
    public function init(): DataResponse
    {
        // administrator only, and the site has to be configured: siteContext('admin') answers both
        [$denied] = $this->siteContext('admin');
        if ($denied !== null) {
            return $denied;
        }
        try {
            return new DataResponse(['ok' => true, 'result' => $this->bridge->initSite()]);
        } catch (\Throwable $e) {
            return $this->translate($e) ?? throw $e;
        }
    }

    /** Fast-forwards from the remote and mirrors the changes back into Nextcloud. */
    public function pull(): DataResponse
    {
        [$denied] = $this->siteContext('admin');
        if ($denied !== null) {
            return $denied;
        }
        try {
            return new DataResponse(['ok' => true, 'result' => $this->bridge->pullSite()]);
        } catch (\Throwable $e) {
            return $this->translate($e) ?? throw $e;
        }
    }
}
