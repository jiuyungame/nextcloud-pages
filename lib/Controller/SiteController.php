<?php

declare(strict_types=1);

namespace OCA\Pages\Controller;

use OCA\Pages\AppInfo\Application;
use OCA\Pages\Service\AuthorSourcesService;
use OCA\Pages\Service\BridgeClient;
use OCA\Pages\Service\BridgeException;
use OCA\Pages\Service\ContentLock;
use OCA\Pages\Service\PairingService;
use OCA\Pages\Service\SiteLayout;
use OCA\Pages\Service\SiteRegistry;
use OCA\Pages\Service\SiteTrees;
use OCA\Pages\Service\SourceArchive;
use OCA\Pages\Service\SourceReplacementException;
use OCA\Pages\Service\SourceReplacementService;
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
 * Site configuration, bridge connection and the two operations that replace the site source.
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
        private AuthorSourcesService $authorSources,
        private SourceArchive $archive,
        private SourceReplacementService $replacement,
        private ContentLock $lock,
        private SiteLayout $layout,
        IL10N $l10n,
    ) {
        parent::__construct(Application::APP_ID, $request, $userSession, $groupManager, $registry, $files, $bridge, $l10n);
    }

    /**
     * The one site. [#NoAdminRequired] because every signed-in user gets the shell: a non-admin is
     * handed {canEdit, canPublish, isAdmin, author_root, site} with the site trimmed down to
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
     * Retries the configuration push a save could not deliver, addressed by nothing: the author is
     * the caller.
     *
     * An author's save writes the file and then calls the same push; when the bridge is down the file
     * is safe, the push is not, and the entry carries a "not pushed · retry" mark in the browser.
     * Clicking it lands here. It runs exactly the push the save path runs
     * ({@see BaseController::syncAuthorSources()}), so there is no second mechanism that could drift:
     * the same payload and the same author grant. The answer is HTTP 200 with `bridge.pushed`, because
     * "the file is saved and the bridge did not take the push" is a state, not an HTTP error.
     *
     * It is idempotent: pushing the same configuration twice replaces itself and only marks the
     * preview dirty, and no commit happens on this path at all (commits are made by
     * POST /api/site/publish), so repeated clicks cannot create one.
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
        // a fresh pairing is a fresh author layout: let the one-shot overwrite run again
        \OCP\Server::get(\OCA\Pages\Service\AuthorDeltaService::class)->forgetOverwrite();
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

    /**
     * Rotate or replace the two pairing secrets and push the configuration to the bridge.
     *
     * An explicit `rotate` generates a fresh pair; two typed secrets replace the stored pair and must
     * be sent together, because the bridge writes both keys in one atomic step. A push the bridge
     * refuses puts the previous pair back, so the app and the bridge keep using the same secrets.
     * Without either flag only the configuration is pushed.
     */
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
        // author content no longer travels with this payload: a save is a file-event delta and the
        // boot overwrite carries the complete state, so this request is configuration and secrets only
        try {
            // a push carries the whole site, and the bridge requires its `source/` directory:
            // create it (and the site folder) before pushing
            $this->layout->ensureSource();
            $result = $this->bridge->pushConfig($secrets === null ? [] : ['secrets' => $secrets]);
        } catch (\Throwable $e) {
            if ($previous !== null) {
                // put the old pair back: the bridge kept using it, so the app must keep using it too
                $this->registry->setSharedSecret($previous[SiteRegistry::KEY_SHARED_SECRET]);
                $this->registry->setSigningSecret($previous[SiteRegistry::KEY_SIGNING_SECRET]);
            }
            return $this->translate($e) ?? throw $e;
        }
        return new DataResponse([
            'ok' => true,
            'result' => $result,
            'revision' => $this->registry->getRevision(),
            'pushed_revision' => $this->registry->getPushedRevision(),
            'has_unpushed' => $this->registry->hasUnpushedChanges(),
            'restart_required' => (bool)($result['restart_required'] ?? false),
            'rotated' => $secrets !== null,
            // no author block is sent any more; the key stays so the settings page's shape is unchanged
            'authors' => [],
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

    /**
     * Store the one site.
     *
     * `push` (the settings page's write-through) makes this one call do both halves: the candidate is
     * validated, pushed to the bridge, and only then stored. A push that fails therefore leaves the
     * stored configuration exactly as it was, and the page can keep showing the previous values - an
     * app that stored a value the bridge refused would be the intermediate state the design forbids.
     *
     * Without `push` this stays the scripted save: the configuration is stored and the bridge is fed by
     * an explicit push later (`POST /api/bridge/push`, `occ pages:push`).
     */
    public function save(): DataResponse
    {
        $denied = $this->requireAdmin();
        if ($denied !== null) {
            return $denied;
        }
        $params = $this->withUneditedFields($this->request->getParams());
        // `push` is a request flag, not part of the site object: it must not be stored or normalised
        $push = filter_var($params['push'] ?? false, FILTER_VALIDATE_BOOLEAN) === true;
        unset($params['push']);
        $stored = $this->registry->getSite();
        try {
            if ($stored !== null) {
                // the two fields that name content are locked once content exists; a key the request
                // does not carry is "unchanged", not "emptied" - a script may send only one field.
                if (array_key_exists('nc_user', $params)) {
                    $this->lock->rejectChange(
                        $this->l10n->t('Site storage user'),
                        (string)($stored['nc_user'] ?? ''),
                        (string)($params['nc_user'] ?? ''),
                    );
                }
                if (array_key_exists('nc_path', $params)) {
                    $this->lock->rejectChange(
                        $this->l10n->t('Site folder'),
                        (string)($stored['nc_path'] ?? ''),
                        (string)($params['nc_path'] ?? ''),
                    );
                }
                // the remote and the branch are locked once the publish repository exists: changing
                // them would silently repoint an existing history. Reset (which deletes the trees) is
                // the only unlock, and the page renders both fields disabled.
                if ($this->initialised()) {
                    foreach (['repo_url' => $this->l10n->t('Git remote (optional)'), 'branch' => $this->l10n->t('Branch')] as $key => $label) {
                        if (!array_key_exists($key, $params)) {
                            continue;
                        }
                        if ((string)($params[$key] ?? '') === (string)($stored[$key] ?? '')) {
                            continue;
                        }
                        throw new \InvalidArgumentException($this->l10n->t(
                            '%1$s cannot be changed once the repository is initialised. Reset Pages first (Danger zone).',
                            [$label],
                        ));
                    }
                }
            }
            if (!$push) {
                $site = $this->registry->setSite($params);

                return new DataResponse([
                    'ok' => true,
                    'site' => $site,
                    'revision' => $this->registry->getRevision(),
                    'has_unpushed_changes' => $this->registry->hasUnpushedChanges(),
                ]);
            }
            return $this->saveAndPush($params);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => 'BAD_REQUEST', 'message' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            // the bridge refused or did not answer: the translation gives the settings page the same
            // JSON shape (and the 502/503 status) as every other bridge call, so its "unreachable"
            // branch works. Nothing was stored, so the page reverts its inputs.
            return $this->translate($e) ?? throw $e;
        }
    }

    /**
     * Whether the publish repository exists yet (the bridge derives it from the working copy).
     *
     * A status call per save is the cheapest reliable answer, and a bridge that answers is always the
     * authority: it knows about a repository the app never created, and it says `initialized:false`
     * again after a reset it performed - so the unlock the reset promises keeps working. Only when
     * the status call fails does the sticky `repo_initialised` flag answer instead: an unreachable
     * bridge must not turn a permanent lock into an open one (a scripted `PUT /api/site` with
     * `push=false` would otherwise be able to repoint an existing history). The flag is set by every
     * path that saw the repository exist and cleared only by the reset, so an install that has not
     * initialised anything yet keeps both fields editable while the bridge is down.
     */
    private function initialised(): bool
    {
        try {
            $running = ($this->bridge->siteStatus()['initialized'] ?? false) === true;
            if ($running) {
                $this->registry->markRepoInitialised();
            }

            return $running;
        } catch (\Throwable $e) {
            // the bridge cannot answer: the last answer the app saw decides (fail-closed)
            return $this->registry->isRepoInitialised();
        }
    }

    /**
     * Store, push, and roll both back together: the write-through the settings page uses.
     *
     * The candidate is validated first, then stored (which bumps the revision), then pushed. Storing
     * first is what makes the bridge receive the *new* revision - the payload is built from the stored
     * site - so `pages:doctor` does not report an out-of-sync configuration after a write-through. A
     * push that fails puts the previous site and the previous revision marker back, so the app never
     * holds a value the bridge refused (and the page reverts its four inputs).
     *
     * @param array<string,mixed> $params the site object as sent
     * @throws \Throwable when the bridge is unreachable or refuses the value
     */
    private function saveAndPush(array $params): DataResponse
    {
        // An empty "site storage user" means "the administrator who is configuring it": resolve it
        // here, once, so the stored value, the push payload and every downstream reader see a concrete
        // uid. The folder is still required - it has no sensible default.
        if (trim((string)($params['nc_user'] ?? '')) === '') {
            $params['nc_user'] = $this->currentUserId();
        }
        $before = $this->registry->getSite();
        $beforeRevision = $this->registry->getRevision();
        $candidate = $this->registry->normalize($params);
        $this->registry->setSite($candidate);
        try {
            // the bridge refuses a site whose source directory is missing, and it creates online/ and
            // pending/ itself: making sure `source/` exists is the one half of the layout this app owes
            $this->layout->ensureSource();
            $outcome = $this->authorSources->pushWithAuthors($this->bridge);
        } catch (\Throwable $e) {
            if ($before === null) {
                $this->registry->forgetSite();
            } else {
                $this->registry->setSite($before);
            }
            $this->registry->restoreRevision($beforeRevision);
            throw $e;
        }
        // a stored configuration is only in force once the bridge rebuilds against it: the
        // write-through ends with the same "rebuild soon" ping a save ends with. A failure here is
        // not a failure of the save - the configuration is pushed, and the next save, build or event
        // triggers the rebuild anyway (reporting it would make the form revert values the bridge
        // already accepted).
        try {
            $this->bridge->notify();
        } catch (\Throwable) {
        }

        return new DataResponse([
            'ok' => true,
            'site' => $this->registry->getSite(),
            'pushed' => true,
            'revision' => $this->registry->getRevision(),
            'has_unpushed_changes' => $this->registry->hasUnpushedChanges(),
            'authors' => $outcome['authors'],
        ]);
    }

    /**
     * Move the author content directory through its own channel.
     *
     * The bridge validates the shape and, when the directory already exists inside the site, asks for
     * ``confirm: true`` (``409 CONFIRM_REQUIRED``); the client shows that confirmation and retries.
     * The value is stored first - the manifest keys and this answer are derived from it - and put back
     * when the bridge refuses the move: the move there is all or nothing, so a refusal (the 409
     * ``AUTHOR_ROOT_MOVE_FAILED`` included) leaves the old root in force on both sides and this method
     * never keeps the candidate. Only an accepted move re-keys the approved manifest entries (the key
     * is the site path). The move itself carries `online/` and `pending/` to the new prefix and
     * rebuilds the commit and review trees under it; the one-shot author overwrite that follows (with
     * the old root named as a purge root) rewrites the preview tree's author area under the new root.
     * The answer carries the bridge's own `moved` counts and `work_rebuild` verdict.
     */
    public function setAuthorRoot(): DataResponse
    {
        [$denied] = $this->siteContext('admin');
        if ($denied !== null) {
            return $denied;
        }
        $root = (string)$this->request->getParam('authorRoot', '');
        $confirm = filter_var($this->request->getParam('confirm', false), FILTER_VALIDATE_BOOLEAN) === true;
        $before = $this->registry->getAuthorRoot();
        try {
            // the registry validates the shape (a typo is a 400 and nothing is stored)
            $this->registry->setAuthorRoot($root);
            $after = $this->registry->getAuthorRoot();
            try {
                $answer = $this->bridge->setAuthorRoot($after, $confirm);
            } catch (\Throwable $e) {
                // the store above came first, so a refused move has to put the value back: the bridge
                // keeps its old root on every failure (a 409 AUTHOR_ROOT_MOVE_FAILED moved the files it
                // had already carried back under the old name and adopted nothing), and an app that
                // kept the candidate would send every reader to a directory that is not in force there
                $this->registry->setAuthorRoot($before);
                throw $this->authorRootMoveFailed($e);
            }
            $pushed = true;
            if ($after !== $before) {
                // every approved file's manifest key is the site path, and the bridge has accepted
                // the move by now (it adopts the new root only after every file followed): only here
                // may the keys follow it. A refused move (409 AUTHOR_ROOT_MOVE_FAILED) is answered
                // above, with the stored root put back and the keys still on the old directory. Author
                // content itself travels through the file-event deltas and the one-shot overwrite, so
                // nothing is pushed here (the old cache lists are gone).
                \OCP\Server::get(\OCA\Pages\Service\ReviewStore::class)->rekeyAuthorRoot($before, $after);
                // The preview tree's author area still names the old root. Forget the one-shot flag
                // so the overwrite runs again, and run it right now with the old root named as a
                // purge root, so the old copies leave the preview tree immediately instead of
                // waiting for a mirror round. Both steps are best-effort: a failure is retried on
                // the next boot.
                $delta = \OCP\Server::get(\OCA\Pages\Service\AuthorDeltaService::class);
                $delta->forgetOverwrite();
                $delta->overwrite([$before]);
            }

            $result = ['ok' => true, 'author_root' => $after, 'pushed' => $pushed];
            // the bridge's account of the move: how many files followed the directory in each layer
            // and whether it rebuilt its derived trees under the new name. An idempotent answer
            // (`changed: false`) moved nothing and carries neither key
            foreach (['moved', 'work_rebuild'] as $key) {
                if (array_key_exists($key, $answer)) {
                    $result[$key] = $answer[$key];
                }
            }

            return new DataResponse($result);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => 'BAD_REQUEST', 'message' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return $this->translate($e) ?? throw $e;
        }
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
     * Bootstraps the publish repository through the bridge, the only component that can run git.
     *
     * "Create" only establishes the repository (and fetches the refs when a remote is set); it never
     * writes the Nextcloud folder - content enters that folder through Files/Import, or through Pull
     * when a remote is configured. Pull is the one path that replaces the folder, with a backup.
     */
    public function init(): DataResponse
    {
        // administrator only, and the site has to be configured: siteContext('admin') answers both
        [$denied] = $this->siteContext('admin');
        if ($denied !== null) {
            return $denied;
        }
        try {
            // the bridge refuses a site whose skeleton directory is missing: create it (and the site
            // folder) before asking it to bootstrap
            $this->layout->ensureSource();
            $result = $this->bridge->initSite();
        } catch (\Throwable $e) {
            return $this->translate($e) ?? throw $e;
        }
        // the repository exists from here on: record it, so the remote/branch lock does not depend
        // on the bridge answering the next time (see initialised())
        $this->registry->markRepoInitialised();

        return new DataResponse(['ok' => true, 'result' => $result]);
    }

    /**
     * What the Pull confirmation has to say: how many commits the remote is ahead, how many local
     * files the replacement will back up and overwrite, and whether the folder has content at all
     * (an empty folder is filled without a confirmation). Read-only apart from the fetch.
     */
    public function pullPreview(): DataResponse
    {
        [$denied, $site] = $this->siteContext('admin');
        if ($denied !== null) {
            return $denied;
        }
        if (!$this->hasRemote($site)) {
            return $this->noRemote();
        }
        try {
            $preview = $this->bridge->pullPreview();
        } catch (\Throwable $e) {
            return $this->translate($e) ?? throw $e;
        }

        return new DataResponse([
            'ok' => true,
            'remote_configured' => true,
            'remote_ahead' => $preview['remote_ahead'] ?? null,
            'reachable' => $preview['reachable'] ?? null,
            'local_unpublished' => (int)($preview['local_unpublished'] ?? 0),
            'head' => (string)($preview['head'] ?? ''),
            'repo' => (bool)($preview['repo'] ?? false),
            // whether the Nextcloud folder holds anything: the client only asks for a confirmation
            // (replacement + backup) when there is something to replace
            'source_has_content' => $this->sourceHasContent($site),
        ]);
    }

    /**
     * Whether the site's **source** folder holds at least one node. A missing folder counts as empty:
     * Pull creates it. Cheap on purpose - one directory listing through the Nextcloud file API.
     *
     * @param array<string,mixed> $site
     */
    private function sourceHasContent(array $site): bool
    {
        $folder = $this->files->existingFolder(
            $this->siteUserId($site),
            rtrim((string)($site['nc_path'] ?? ''), '/') . '/' . SiteTrees::SOURCE,
        );
        if ($folder === null) {
            return false;
        }
        foreach ($folder->getDirectoryListing() as $node) {
            if ($node->getName() !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Pull: fast-forward the commit tree from the remote and replace the site **source** folder with
     * that content, whole.
     *
     * Order matters. The commit tree is pulled first (a failure there changes nothing in Nextcloud),
     * then the archive of the new HEAD is downloaded and verified against the sha256 the pull answer
     * named, and only then is the source folder backed up, emptied and rewritten. There is no merge and
     * no rebase: the remote's content replaces the local content on both sides, and the local
     * unpublished modifications the confirmation counted are kept in the backup, not merged.
     *
     * Only `source/` is replaced: `online/` and `pending/` are the bridge's own write targets, and a
     * pull must not present published content as the site's skeleton. The bridge archives the flat
     * repository (its root is the site itself) minus the paths it knows as published, so the archive
     * is source by intent; `source_only` is always true now and is only echoed for older callers.
     */
    public function pull(): DataResponse
    {
        [$denied, $site] = $this->siteContext('admin');
        if ($denied !== null) {
            return $denied;
        }
        if (!$this->hasRemote($site)) {
            return $this->noRemote();
        }
        $owner = $this->siteUserId($site);
        $path = rtrim((string)$site['nc_path'], '/') . '/' . SiteTrees::SOURCE;

        try {
            $pull = $this->bridge->pullSite();
            $bytes = $this->bridge->siteArchive();
            $expected = (string)($pull['archive']['sha256'] ?? '');
            if ($expected !== '' && !hash_equals($expected, hash('sha256', $bytes))) {
                throw new BridgeException(
                    $this->l10n->t('The archive the bridge produced does not match the hash it reported; the Nextcloud folder was not touched.'),
                    'ARCHIVE_MISMATCH',
                );
            }
            $read = $this->archive->read($bytes, 'pull.tar.gz', false);
            $result = $this->replacement->replace($owner, $path, $read['files'], 'pull');
        } catch (SourceReplacementException $e) {
            return $this->replacementFailed($e);
        } catch (\Throwable $e) {
            return $this->translate($e) ?? throw $e;
        }

        return new DataResponse([
            'ok' => true,
            'files' => $result['files'],
            'skipped' => $read['skipped'],
            'backed_up_to' => $result['backup']['path'],
            'backup' => $result['backup'],
            'revision' => $this->registry->getRevision(),
            'head' => (string)($pull['head'] ?? ''),
            'changed' => count((array)($pull['changed'] ?? [])),
            // the bridge packages the flat repository minus the online/ paths; source_only is always
            // true, kept so a client can assert the archive is source and never a whole-tree dump
            'source_only' => ($pull['archive']['source_only'] ?? null) === true,
            'bridge' => $this->rebuildPreview(),
        ]);
    }

    /**
     * Import: a zip/tgz replaces the site **source** folder, after it is validated and backed up.
     *
     * The archive is read in memory and written back through the Nextcloud file API as the site's
     * storage user - never through the data directory, or the file cache would disagree with what is
     * on disk and the Files app would show a folder that is not there. Only `source/` is replaced:
     * `online/` and `pending/` belong to the bridge.
     */
    public function importSource(): DataResponse
    {
        [$denied, $site] = $this->siteContext('admin');
        if ($denied !== null) {
            return $denied;
        }
        $upload = $this->request->getUploadedFile('archive');
        if (!is_array($upload) || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return new DataResponse([
                'error' => 'NO_ARCHIVE',
                'message' => $this->l10n->t('No archive was uploaded (field "archive"); a zip or tar.gz of the site source is required.'),
            ], 400);
        }
        $size = (int)($upload['size'] ?? 0);
        if ($size <= 0) {
            return new DataResponse(['error' => 'EMPTY_ARCHIVE', 'message' => $this->l10n->t('The uploaded archive is empty')], 400);
        }
        if ($size > SourceArchive::MAX_TOTAL_BYTES) {
            return new DataResponse([
                'error' => 'ARCHIVE_TOO_LARGE',
                'message' => $this->l10n->t('The archive is too large; the limit is %s.', [$this->files->bytesLabel(SourceArchive::MAX_TOTAL_BYTES)]),
            ], 413);
        }
        $tmp = (string)($upload['tmp_name'] ?? '');
        $bytes = $tmp !== '' && is_uploaded_file($tmp) ? (string)file_get_contents($tmp) : '';
        if ($bytes === '') {
            return new DataResponse(['error' => 'NO_ARCHIVE', 'message' => $this->l10n->t('The uploaded archive could not be read')], 400);
        }

        $owner = $this->siteUserId($site);
        $path = rtrim((string)$site['nc_path'], '/') . '/' . SiteTrees::SOURCE;
        try {
            $read = $this->archive->read($bytes, (string)($upload['name'] ?? ''), true);
            $result = $this->replacement->replace($owner, $path, $read['files'], 'import');
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => 'BAD_ARCHIVE', 'message' => $e->getMessage()], 400);
        } catch (SourceReplacementException $e) {
            return $this->replacementFailed($e);
        } catch (\Throwable $e) {
            // a write or backup failure is a server-side problem, not a bad archive: the translation
            // layer reports what it can explain and lets anything else surface as an error
            return $this->translate($e) ?? throw $e;
        }

        return new DataResponse([
            'ok' => true,
            'files' => $result['files'],
            'skipped' => $read['skipped'],
            'backed_up_to' => $result['backup']['path'],
            'backup' => $result['backup'],
            'revision' => $this->registry->getRevision(),
            'bridge' => $this->rebuildPreview(),
        ]);
    }

    /**
     * A replacement that failed: the folder was backed up first, and unless the undo failed too, put
     * back to that backup.
     *
     * 409, not 423: the failure is "the folder could not be written right now / is held by another
     * request" - a conflict with the state the request found, and one a retry may well clear - which
     * is what Conflict means; 423 (Locked) belongs to WebDAV's own locking protocol, and a JSON client
     * has no lock token to send. The answer keeps the shape the success path has: `backed_up_to` names
     * the backup that exists even though the request failed, and `rollback` says whether the site is
     * back at that backup (`false`: the folder is in an unknown state, the backup is the only way back).
     */
    private function replacementFailed(SourceReplacementException $e): DataResponse
    {
        return new DataResponse([
            'error' => 'SITE_REPLACEMENT_FAILED',
            'message' => $this->l10n->t('The site folder could not be replaced: %s', [$e->getMessage()]),
            'backed_up_to' => (string)($e->getBackup()['path'] ?? ''),
            'backup' => $e->getBackup(),
            'rollback' => $e->isRolledBack() === true,
        ], Http::STATUS_CONFLICT);
    }

    /** Whether a push/pull is possible at all: the site's remote is what decides. */
    private function hasRemote(array $site): bool
    {
        return trim((string)($site['repo_url'] ?? '')) !== '';
    }

    /** Pull without a remote is not a failure of the request but a state: say which setting is missing. */
    private function noRemote(): DataResponse
    {
        return new DataResponse([
            'error' => 'NO_REMOTE',
            'message' => $this->l10n->t('This site has no git remote, so there is nothing to pull from. Set one in the site settings, save, then try again.'),
        ], 412);
    }

    /**
     * Rebuild the three derived trees and the preview, after a replacement.
     *
     * This is Apply source (`mirror`): the source is already in place at this point, and the trees are
     * pure functions of `source/` + `online/` + `pending/`, so the rebuild is always safe. A bridge
     * that does not answer is reported in the answer and never undoes the replacement: the next Apply
     * source brings the trees up to date, while rolling the folder back would throw away exactly what
     * the administrator imported.
     *
     * @return array<string,mixed>
     */
    private function rebuildPreview(): array
    {
        try {
            $result = $this->bridge->mirror($this->authorSources->authorAreas(), true);

            return [
                'ok' => true,
                'build' => is_array($result['build'] ?? null) ? $result['build'] : null,
                'mirror' => $result,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => $e instanceof BridgeException ? $e->getBridgeCode() : 'ERROR',
                'message' => $e->getMessage(),
            ];
        }
    }
}
