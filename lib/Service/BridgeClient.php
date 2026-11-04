<?php

declare(strict_types=1);

namespace OCA\Pages\Service;

use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * HTTP client for pages-bridge. Every request goes through rawRequest(), which disables the proxy,
 * and health() enforces the protocol version before anything else is trusted. Signed calls carry the
 * shared secret; pair() is the one deliberate exception, because it happens before a secret exists.
 */
class BridgeClient
{
    /** Must match pages_bridge.PROTOCOL. */
    public const PROTOCOL = 3;

    /**
     * The review-tree operations of the author-delta route: what a reviewer's decision and an author's
     * request need from the bridge ({@see self::reviewOp()}).
     *
     * @var list<string>
     */
    public const REVIEW_OPS = ['candidate', 'publish', 'discard', 'take-down'];

    private const TIMEOUT = 30;
    private const BUILD_TIMEOUT = 300;

    /**
     * The bridge's own default for `build_timeout_s` (`bridge/pages-bridge.toml.example`). It is used
     * here only when this app never pushed a value, because the bridge then runs on its TOML or on
     * that default and the app has no other way to know which one.
     */
    private const DEFAULT_BUILD_TIMEOUT = 180;

    /**
     * What the bridge waits for the site lock on top of `build_timeout_s`: every tree writer takes
     * `runtime.lock` with `timeout=build_timeout_s + 30` (pages_bridge/server.py, `_site_lock`). An
     * author delta, a build, a publish, a pull and a reset are all those writers, so a client timeout
     * below that window would give up on an operation the bridge is still about to apply.
     */
    private const LOCK_WAIT_MARGIN = 30;

    /**
     * The call's own work (a whole-folder mirror, the one-shot overwrite, the commit and the push,
     * the deletion of a reset) starts after the lock is held, so the client needs a further margin to
     * be the last one to give up, not the first.
     */
    private const WRITE_BUFFER = 30;

    /**
     * Ceiling for the computed lock-bound timeout: however large the pushed `build_timeout_s`, one
     * request must not hold a web request open for an hour (the framework and the proxy usually give
     * up much earlier, and a stuck request is worse than a retry).
     */
    private const WRITE_TIMEOUT_MAX = 3600;

    public function __construct(
        private IClientService $clientService,
        private SiteRegistry $registry,
        private IConfig $config,
        private LoggerInterface $logger,
        private IL10N $l10n,
    ) {
    }

    /**
     * @param array<string,string> $headers
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, array $headers = [], ?string $body = null, int $timeout = self::TIMEOUT): array
    {
        return $this->decode(...$this->signedRequest($method, $path, $headers, $body, $timeout));
    }

    /**
     * One request against the configured bridge, authenticated with the shared secret.
     *
     * @param array<string,string> $headers
     * @return array{0:int,1:string,2:array<string,string>}
     */
    private function signedRequest(string $method, string $path, array $headers = [], ?string $body = null, int $timeout = self::TIMEOUT): array
    {
        $base = $this->registry->getBridgeUrl();
        if ($base === '') {
            throw new BridgeException($this->l10n->t('The bridge URL is not configured'), 'NOT_CONFIGURED');
        }
        $secret = $this->registry->getSharedSecret();
        if ($secret === '') {
            throw new BridgeException($this->l10n->t('The shared secret is not configured'), 'NOT_CONFIGURED');
        }
        return $this->rawRequest($base, $method, $path, ['Authorization' => 'Bearer ' . $secret], $body, $timeout);
    }

    /**
     * Sends one request to an explicit base URL and returns [status, raw body, headers].
     *
     * The base URL is passed in because pairing talks to a bridge that has no working configuration
     * yet (and, in the UNPAIRED state, only /health and /pair answer without the shared secret).
     * Losing the explicit URL here would leave a failure pointing at a stale stored address.
     *
     * @param array<string,string> $headers
     * @return array{0:int,1:string,2:array<string,string>}
     */
    private function rawRequest(string $base, string $method, string $path, array $headers = [], ?string $body = null, int $timeout = self::TIMEOUT): array
    {
        $url = $base . $path;
        $allHeaders = array_merge(['Accept' => 'application/json'], $headers);

        // loopback target: use the stream channel at once, the framework always rejects it and logs it
        if ($this->isLoopbackUrl($base) && $this->useLoopbackBypass()) {
            return $this->streamRequest($method, $url, $allHeaders, $body, $timeout);
        }

        try {
            return $this->frameworkRequest($method, $url, $allHeaders, $body, $timeout);
        } catch (\OCP\Http\Client\LocalServerException $e) {
            // not a loopback host but judged local (e.g. resolves to an internal IP): still offer the narrow channel
            if (!$this->isLoopbackUrl($base) || !$this->useLoopbackBypass()) {
                throw new BridgeException(
                    $this->l10n->t('The bridge is on a local address, and Nextcloud refuses app access to local addresses by default.'),
                    'LOCAL_ACCESS_BLOCKED',
                    [
                        'url' => $base,
                        'options' => [
                            $this->l10n->t('Recommended: keep the Pages loopback channel enabled (app setting bridge_loopback_bypass, on by default, effective for 127.0.0.1/::1/localhost only)'),
                            $this->l10n->t('Or: set allow_local_remote_servers => true in config.php (this relaxes the global SSRF protection for every app)'),
                        ],
                    ],
                );
            }
            $this->logger->debug('pages: framework client refused the local address, using the loopback stream channel', ['path' => $path]);
            return $this->streamRequest($method, $url, $allHeaders, $body, $timeout);
        } catch (\Throwable $e) {
            $this->logger->warning('pages: cannot reach pages-bridge', ['path' => $path, 'exception' => $e]);
            throw new BridgeException(
                $this->l10n->t('Cannot reach pages-bridge (%s). Make sure the service is running and the URL is correct.', [$base]),
                'UNREACHABLE',
                ['url' => $base, 'reason' => $e->getMessage()],
            );
        }
    }

    /**
     * Framework client (Nextcloud IClientService), keeping its built-in protections and logging.
     *
     * @param array<string,string> $headers
     * @return array{0:int,1:string,2:array<string,string>}
     */
    private function frameworkRequest(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        $options = [
            'timeout' => $timeout,
            'http_errors' => false,
            'headers' => $headers,
            // a host-level http_proxy without a loopback entry in no_proxy makes libcurl proxy local calls too
            'curl' => [\CURLOPT_NOPROXY => '*'],
        ];
        if ($body !== null) {
            $options['body'] = $body;
        }
        $response = $this->clientService->newClient()->request($method, $url, $options);
        $normalized = [];
        foreach ($response->getHeaders() as $name => $value) {
            $normalized[strtolower((string)$name)] = is_array($value) ? implode(', ', $value) : (string)$value;
        }
        return [$response->getStatusCode(), (string)$response->getBody(), $normalized];
    }

    /**
     * Plain-PHP loopback channel (http stream wrapper), used only when the framework refuses local
     * addresses: it never connects to LAN or public addresses, the URL comes from admin config, and
     * `bridge_loopback_bypass=false` disables it in favour of the global switch. The stream wrapper
     * does not read http_proxy, so this path cannot be hijacked by a proxy.
     *
     * @param array<string,string> $headers
     * @return array{0:int,1:string,2:array<string,string>}
     */
    private function streamRequest(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        if (!filter_var(\ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            throw new BridgeException(
                $this->l10n->t('allow_url_fopen is disabled, so the loopback channel is unavailable'),
                'LOCAL_ACCESS_BLOCKED',
            );
        }
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headerLines),
                'content' => $body ?? '',
                'timeout' => $timeout,
                'ignore_errors' => true,   // keep the body for 4xx/5xx too
                'follow_location' => 0,
                'proxy' => '',             // explicitly disable the stream wrapper proxy
                'request_fulluri' => false,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            $err = error_get_last();
            throw new BridgeException(
                $this->l10n->t('Loopback request failed: %s', [$err['message'] ?? $this->l10n->t('unknown error')]),
                'UNREACHABLE',
                ['url' => $url],
            );
        }
        $status = 0;
        $normalized = [];
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int)$m[1];
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $normalized[strtolower(trim($name))] = trim($value);
            }
        }
        return [$status, $raw, $normalized];
    }

    private function isLoopbackUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }
        if (strcasecmp($host, 'localhost') === 0) {
            return true;
        }
        $ip = filter_var($host, FILTER_VALIDATE_IP);
        if ($ip === false) {
            return false;
        }
        // 127.0.0.0/8 and ::1
        return str_starts_with($ip, '127.') || $ip === '::1';
    }

    private function useLoopbackBypass(): bool
    {
        return $this->registry->isLoopbackBypassEnabled();
    }

    /** @return array<string,mixed> */
    private function decode(int $status, string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new BridgeException(
                $this->l10n->t('pages-bridge returned a non-JSON response (HTTP %s). If the host sets http_proxy, make sure no_proxy covers the loopback address.', [(string)$status]),
                'BAD_RESPONSE',
                ['status' => $status, 'body' => mb_substr($raw, 0, 300)],
            );
        }
        if ($status >= 400) {
            throw new BridgeException(
                (string)($decoded['message'] ?? $this->l10n->t('pages-bridge returned an error')),
                (string)($decoded['error'] ?? 'BRIDGE_ERROR'),
                is_array($decoded['details'] ?? null) ? $decoded['details'] : [],
                $status,
            );
        }
        return $decoded;
    }

    /**
     * A bare liveness probe: one **unsigned** GET to the bridge's read-only `/health`.
     *
     * It exists for the unauthenticated `GET /api/bridge/ping` and for anything that has to tell "the
     * companion service is there" from "Nextcloud is there" without a credential: no shared secret, no
     * protocol gate (a version mismatch is a detail of the *answer*, not a reason to hide it), and a
     * short timeout so a dead service does not hold a probe open for 30 s.
     *
     * @return array{ok:bool,version:string,protocol:int}
     */
    public function ping(int $timeout = 3): array
    {
        $base = $this->registry->getBridgeUrl();
        if ($base === '') {
            throw new BridgeException($this->l10n->t('The bridge URL is not configured'), 'NOT_CONFIGURED');
        }
        [$status, $raw] = $this->rawRequest($base, 'GET', '/health', [], null, max(1, $timeout));
        $answer = $this->decode($status, $raw);

        return [
            'ok' => ($answer['ok'] ?? false) === true,
            'version' => (string)($answer['version'] ?? ''),
            'protocol' => (int)($answer['protocol'] ?? 0),
        ];
    }

    /** @return array<string,mixed> */
    public function health(): array
    {
        $health = $this->request('GET', '/health');
        $protocol = (int)($health['protocol'] ?? 0);
        if ($protocol !== self::PROTOCOL) {
            throw new BridgeException(
                $this->l10n->t(
                    'pages-bridge speaks protocol %1$s, this app requires %2$s. Update the bridge (the app package contains the bridge/ directory).',
                    [(string)$protocol, (string)self::PROTOCOL],
                ),
                'PROTOCOL_MISMATCH',
                ['bridge_protocol' => $protocol, 'app_protocol' => self::PROTOCOL],
            );
        }
        return $health;
    }

    /**
     * Whether a shared secret is stored, which is what "paired" means for this app. A bridge that
     * still answers /health is reachable but useless without it, and the settings form has to say so.
     */
    public function isPaired(): bool
    {
        return $this->registry->isConnected();
    }

    /**
     * Nextcloud's data directory, discovered instead of typed: the bridge needs the very same path
     * to reach `<data>/<user>/files/<nc_path>`, and asking the administrator to copy it out of
     * `occ config:system:get datadirectory` was one of the steps pairing removes.
     */
    public function discoverNcDataRoot(): string
    {
        return rtrim(trim((string)$this->config->getSystemValue('datadirectory', '')), '/');
    }

    /** @return array<string,mixed> */
    public function environment(): array
    {
        return $this->request('GET', '/env');
    }

    /**
     * Pairs this app with a bridge that has no working configuration yet.
     *
     * Both secrets are generated by the caller and travel in this one request, so there is no
     * half-paired state: the bridge validates the one-time code and writes the secrets atomically.
     * The request is deliberately not signed - the bridge has no secret to check yet - and the URL
     * is explicit, because pairing happens before a bridge address is stored.
     *
     * @param array<string,mixed> $extra `global` and/or `site` to hand over in the same request
     * @return array<string,mixed>
     */
    public function pair(string $url, string $code, string $sharedSecret, string $sessionSigningSecret, string $ncDataRoot, array $extra = []): array
    {
        $base = rtrim(trim($url), '/');
        if (!preg_match('#^https?://#i', $base)) {
            throw new BridgeException($this->l10n->t('The bridge URL must start with http:// or https://'), 'BAD_URL');
        }
        // a pasted code often arrives with the spaces or dashes install.sh prints around it: strip
        // them here, and reject an obvious fragment instead of spending one of the three attempts
        $code = str_replace([' ', '-'], '', trim($code));
        if ($code === '') {
            throw new BridgeException($this->l10n->t('A pairing code is required'), 'NO_CODE');
        }
        if (!preg_match('/^[A-Za-z0-9]{6,32}$/', $code)) {
            throw new BridgeException(
                $this->l10n->t('That does not look like a pairing code; copy the one bridge/install.sh printed'),
                'BAD_CODE',
                ['url' => $base],
            );
        }

        $payload = [
            'code' => $code,
            'shared_secret' => $sharedSecret,
            'session_signing_secret' => $sessionSigningSecret,
            'nc_data_root' => $ncDataRoot,
            'revision' => $this->registry->getRevision(),
        ];
        // only what the administrator already set: an omitted key leaves the bridge on its TOML value
        $global = is_array($extra['global'] ?? null) ? BridgeParams::coerceMap($extra['global']) : [];
        if ($global !== []) {
            $payload['global'] = $global;
        }
        $site = is_array($extra['site'] ?? null) ? $extra['site'] : null;
        if ($site !== null && trim((string)($site['nc_user'] ?? '')) !== '') {
            $payload['site'] = $site;
        }
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            [$status, $raw] = $this->rawRequest(
                $base,
                'POST',
                '/pair',
                ['Content-Type' => 'application/json'],
                $body === false ? '{}' : $body,
            );
        } catch (BridgeException $e) {
            // the client turned an unreachable network into UNREACHABLE; keep the address in the message
            throw new BridgeException(
                $this->l10n->t('Cannot reach pages-bridge (%s). Make sure the service is running and the URL is correct.', [$base]),
                $e->getBridgeCode(),
                $e->getDetails() + ['url' => $base],
            );
        }
        $answer = $this->decode($status, $raw);
        if ($status !== 200 || ($answer['paired'] ?? false) !== true) {
            throw new BridgeException(
                $this->l10n->t('The bridge did not confirm the pairing'),
                'PAIR_FAILED',
                ['url' => $base, 'status' => $status],
                $status,
            );
        }
        $protocol = (int)($answer['protocol'] ?? 0);
        if ($protocol !== self::PROTOCOL) {
            throw new BridgeException(
                $this->l10n->t(
                    'pages-bridge speaks protocol %1$s, this app requires %2$s. Update the bridge (the app package contains the bridge/ directory).',
                    [(string)$protocol, (string)self::PROTOCOL],
                ),
                'PROTOCOL_MISMATCH',
                ['bridge_protocol' => $protocol, 'app_protocol' => self::PROTOCOL],
            );
        }
        return $answer;
    }

    /**
     * Pushes the app's configuration to the bridge; the app is the source of truth.
     *
     * Protocol 3 is single-site: the payload is one site object under `site`, and no route, state
     * file or token carries an id any more. Everything else is the global block, which only ever
     * carries the parameters the administrator set (an absent key keeps the bridge's own value).
     *
     * The protocol still accepts an `authors` list inside `site`, but this app no longer sends one:
     * author content is event-driven ({@see AuthorDeltaService}) and the one-shot overwrite carries
     * the complete state. The override is retained for protocol compatibility, and an absent or empty
     * list keeps the bridge on the single-source behaviour.
     *
     * @param array{global?:array<string,mixed>,site?:array<string,mixed>|null,secrets?:array<string,string>,authors?:list<array<string,mixed>>} $overrides
     * @return array<string,mixed>
     */
    public function pushConfig(array $overrides = []): array
    {
        $site = array_key_exists('site', $overrides) ? $overrides['site'] : $this->registry->getSite();
        if ($site === null) {
            throw new BridgeException($this->l10n->t('No sites are configured yet'), 'NO_SITES');
        }
        // The site directory every author's tree is mirrored into and the preview directory the
        // build runs in travel with every push, so the bridge's validation, its mirror exclusions
        // and its reports all use the same values the app stores.
        $site = $this->registry->siteForBridge($site);
        $authors = is_array($overrides['authors'] ?? null) ? $overrides['authors'] : [];
        if ($authors !== []) {
            // a copy: the caller's array is not modified, and an existing key is replaced deliberately
            $site['authors'] = array_values($authors);
        }
        $global = BridgeParams::coerceMap(
            array_key_exists('global', $overrides) ? (is_array($overrides['global']) ? $overrides['global'] : []) : $this->registry->getGlobal(),
        );

        $revision = $this->registry->getRevision();
        $payload = ['revision' => $revision, 'global' => (object)$global, 'site' => $site];
        // a rotation authenticates with the stored (old) secret, which signedRequest still sends
        $secrets = $this->secretPair($overrides['secrets'] ?? null);
        if ($secrets !== null) {
            $payload['secrets'] = $secrets;
        }
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $result = $this->request(
            'POST',
            '/admin/config',
            ['Content-Type' => 'application/json'],
            $body === false ? '{}' : $body,
        );
        $this->registry->markPushed($revision);
        return $result;
    }

    /**
     * The two-key rotation payload, or null when this is not a rotation.
     *
     * Both keys are sent together whenever either of them changes: the bridge replaces the pair
     * atomically, and an empty signing secret would be rejected by its own validation, so a
     * half-typed pair must not travel at all.
     *
     * @param mixed $secrets
     * @return array{shared_secret:string,session_signing_secret:string}|null
     */
    private function secretPair(mixed $secrets): ?array
    {
        if (!is_array($secrets)) {
            return null;
        }
        $shared = trim((string)($secrets['shared_secret'] ?? ''));
        $signing = trim((string)($secrets['session_signing_secret'] ?? ''));
        if ($shared === '' || $signing === '') {
            return null;
        }
        return ['shared_secret' => $shared, 'session_signing_secret' => $signing];
    }

    /** @return array<string,mixed> */
    public function siteStatus(): array
    {
        return $this->request('GET', '/site/status');
    }

    /**
     * The two Nextcloud content directories as `target => mtime`, in one read-only request.
     *
     * `online/` and `pending/` are written by the bridge itself, never through Nextcloud's Files API:
     * a file lands through a temporary file and a rename, so on an instance with the default
     * `filesystem_check_changes=0` the filecache does not know it until something scans the folder.
     * An app that reads them with `Folder::getDirectoryListing()` therefore answers "not live" for a
     * page that was just published and "nothing pending" for a candidate that was just frozen.
     * `GET /site/paths` walks the same folders on the bridge's own host - no filecache involved - and
     * this method turns its answer into the two maps {@see SiteTrees} keeps.
     *
     * Read-only and lock-free on the bridge (two directory walks, no git, no build), so the 30 s
     * default timeout is the right one, not the lock-bound budget: this call must never wait for a
     * running build. A target is keyed exactly like the manifest keys it (site-relative, forward
     * slashes, no leading slash); a missing moment reads as 0.
     *
     * @return array{online:array<string,int>,pending:array<string,int>}
     * @throws BridgeException when the bridge is not configured, unreachable or answers nonsense
     */
    public function pathsSnapshot(): array
    {
        $answer = $this->request('GET', '/site/paths');

        return [
            'online' => self::stampMap($answer['online_at'] ?? null, $answer['online'] ?? null),
            'pending' => self::stampMap($answer['pending_at'] ?? null, $answer['pending'] ?? null),
        ];
    }

    /**
     * One `target => mtime` map out of the bridge's answer.
     *
     * The map is what the app needs. The list is the fallback for an answer that names targets
     * without their moments: those read as 0, which is "there, but no moment" - still the usable
     * answer for "is it live", while dropping the target would report a published page as gone.
     *
     * @param mixed $stamps the bridge's `online_at` / `pending_at`
     * @param mixed $targets the bridge's `online` / `pending`
     * @return array<string,int>
     */
    private static function stampMap(mixed $stamps, mixed $targets): array
    {
        $out = [];
        if (is_array($stamps)) {
            foreach ($stamps as $target => $at) {
                $key = is_string($target) ? ltrim(trim(str_replace('\\', '/', $target)), '/') : '';
                if ($key !== '') {
                    $out[$key] = is_numeric($at) ? (int)$at : 0;
                }
            }
        }
        if ($out !== [] || !is_array($targets)) {
            return $out;
        }
        foreach ($targets as $target) {
            $key = is_string($target) ? ltrim(trim(str_replace('\\', '/', $target)), '/') : '';
            if ($key !== '') {
                $out[$key] = 0;
            }
        }

        return $out;
    }

    /**
     * Bootstraps the site: an empty working copy is really cloned (full history and upstream
     * tracking), an empty source is back-filled. Uses BUILD_TIMEOUT because clone can be slow.
     *
     * @return array<string,mixed>
     */
    public function initSite(): array
    {
        return $this->request(
            'POST',
            '/site/init',
            [],
            null,
            self::BUILD_TIMEOUT,
        );
    }

    /**
     * The bridge half of "reset Pages": delete both derived trees and drop the stored configuration.
     *
     * The address and the secret are passed in because the retry of a cleanup that failed runs after
     * the app has already cleared its own keys: the credentials then come from the pending-reset
     * record. A bridge that no longer holds a credential answers "already clean" (UNPAIRED, 503) and
     * that is a success - the cleanup is idempotent.
     *
     * @return array<string,mixed>
     */
    public function resetSite(string $url, string $secret): array
    {
        $base = rtrim(trim($url), '/');
        if (!preg_match('#^https?://#i', $base)) {
            throw new BridgeException($this->l10n->t('The bridge URL must start with http:// or https://'), 'BAD_URL');
        }
        $headers = ['Content-Type' => 'application/json'];
        if (trim($secret) !== '') {
            $headers['Authorization'] = 'Bearer ' . trim($secret);
        }
        // the reset is a tree writer like the rest: `do_reset` takes the site lock and waits
        // `build_timeout_s + 30` for it, so the lock-bound timeout is the one that lets a reset
        // issued behind a running build finish instead of being reported failed half-way through
        [$status, $raw] = $this->rawRequest(
            $base,
            'POST',
            '/site/reset',
            $headers,
            '{}',
            $this->lockBoundTimeout(),
        );
        if ($status === 503) {
            $answer = json_decode($raw, true);
            if (is_array($answer) && ($answer['error'] ?? '') === 'UNPAIRED') {
                return ['ok' => true, 'already_clean' => true, 'url' => $base];
            }
        }

        return ['url' => $base] + $this->decode($status, $raw);
    }

    /**
     * One author-content delta (or the one-shot full overwrite).
     *
     * The route holds the bridge's site lock for the whole request - the same lock a build, a publish
     * or a mirror round holds - and waits up to `build_timeout_s + 30` for it. The 30 s default would
     * therefore abandon a save whose delta the bridge applies a moment later: the delta would still
     * land, but the app would have reported a failure for it (this is what the save path swallowed,
     * see {@see AuthorDeltaService::send()}). {@see self::lockBoundTimeout()} derives the timeout
     * from the same number the bridge waits with - as {@see self::publish()} does, for the same
     * reason.
     *
     * The default timeout is the lock-bound one. A caller that must not inherit that budget - the
     * replay of {@see DeltaQueue}, which runs inside a boot and is bounded per entry - passes its
     * own `$timeout`; the delta is idempotent, so a replay that gives up is simply retried later.
     *
     * @param array<string,mixed> $body
     * @param int|null $timeout seconds; null keeps {@see self::lockBoundTimeout()}
     * @return array<string,mixed>
     */
    public function authorDelta(array $body, ?int $timeout = null): array
    {
        $json = json_encode($body);
        return $this->request(
            'POST',
            '/site/author-delta',
            ['Content-Type' => 'application/json'],
            $json === false ? '{}' : $json,
            $timeout !== null && $timeout > 0 ? $timeout : $this->lockBoundTimeout(),
        );
    }

    /**
     * How long one call that waits for the bridge's site lock may take, derived from that window.
     *
     * The bridge waits `build_timeout_s + 30` for `runtime.lock` before it does the work of a tree
     * writer - an author delta, a build, a publish, a pull, a reset (pages_bridge/server.py). The
     * value it waits with is the one this app pushed (see {@see SiteRegistry::GLOBAL_TYPES}), so a
     * pushed value is exact. Without a push the bridge runs on its TOML value or the 180 s default,
     * which this app cannot see - the floor is {@see self::BUILD_TIMEOUT}, so a lock-bound call is
     * never *shorter*-lived than a build that could be holding the lock. The buffer after the lock is
     * the call's own work, which only starts once the lock is held.
     *
     * Read-only routes take no lock and do not use this: they stay on {@see self::TIMEOUT}
     * (`publishedContent()`, `fetchPreview()` and the status calls) or on the bounded transfer
     * timeout (`pullPreview()`, `siteArchive()`), where a lock-bound wait would only turn a slow
     * answer into a held-open request.
     *
     * @return int seconds
     */
    private function lockBoundTimeout(): int
    {
        $raw = $this->registry->getGlobal()['build_timeout_s'] ?? null;
        $build = is_numeric($raw) ? (int)$raw : self::DEFAULT_BUILD_TIMEOUT;
        if ($build <= 0) {
            $build = self::DEFAULT_BUILD_TIMEOUT;
        }

        return min(
            max(self::BUILD_TIMEOUT, $build + self::LOCK_WAIT_MARGIN + self::WRITE_BUFFER),
            self::WRITE_TIMEOUT_MAX,
        );
    }

    /**
     * One of the four review-tree operations of the author-delta route:
     *
     *   candidate  freeze the source file's current bytes into `pending/` and the review tree; the
     *              answer carries the sha256 of those bytes as `digest`
     *   publish    move `pending/<target>` to `online/<target>` (Nextcloud) and into the two host trees
     *   discard    delete `pending/<target>` (idempotent: discarding what is not there is a no-op)
     *   take-down  remove `online/<target>` (Nextcloud) and the file in the work and review trees
     *
     * A thin wrapper over {@see self::authorDelta()} on purpose: the transport, the timeout (the
     * lock-bound timeout, not the 30 s default - a decision can wait for a build's lock exactly like a
     * save can) and the error decoding are the same request the ordinary deltas use, and a second
     * implementation would be a second place for them to drift. The target is passed **site-relative**
     * (`<author_root>/<uid>/<rel>`, no leading slash), which is the shape the bridge's own validation
     * accepts; the manifest stores the leading-slash form, so the callers normalise at this boundary.
     *
     * `$digest` is the approval's binding to the reviewed bytes: the hex sha256 a `candidate`
     * answered. `publish` sends it back, and the bridge refuses with `DIGEST_MISMATCH` when
     * `pending/` (or, on an idempotent retry, `online/`) holds anything else. `null` keeps the old
     * request shape for callers that have no digest, and the bridge then logs a warning.
     *
     * @param string $op one of {@see self::REVIEW_OPS}
     * @return array<string,mixed> the bridge's answer; `candidate` adds `digest`
     * @throws BridgeException when the bridge refuses the operation (a candidate for a source that is
     *         not there, a publish without a candidate, a publish whose bytes changed, …)
     */
    public function reviewOp(string $op, string $uid, string $target, ?string $digest = null): array
    {
        if (!in_array($op, self::REVIEW_OPS, true)) {
            throw new \InvalidArgumentException($this->l10n->t('Unknown review operation: %s', [$op]));
        }
        $body = [
            'op' => $op,
            'uid' => $uid,
            'target' => ltrim(trim(str_replace('\\', '/', $target)), '/'),
        ];
        $digest = $digest === null ? '' : trim($digest);
        if ($digest !== '') {
            $body['digest'] = $digest;
        }

        return $this->authorDelta($body);
    }

    /**
     * **Apply source**: rebuild all three derived trees from the Nextcloud folders.
     *
     * It is the `mirror` op of the author-delta route (the bridge also serves it as `POST /site/mirror`,
     * for an operator; this is the path the app uses). Every tree is a pure function of its inputs -
     * `work` = source → online, `preview` = source → online → author files, `onlinepreview` = source →
     * online → pending - so a complete rebuild is always safe.
     *
     * `$authors` must cover **every** author area (the callers pass
     * {@see AuthorSourcesService::authorAreas()}): the bridge layers the named folders into the preview
     * tree, and a uid that is not named contributes no drafts - the tree would quietly lose pages it
     * cannot see.
     *
     * The bridge's answer carries `rebuilt`, `files` (`work`/`preview`/`onlinepreview` counts) and,
     * when `build` is asked for, the build's own result. A refused round answers HTTP 200 with
     * `ok: false` and the bridge's `error` block (`MIRROR_DELETE_GUARD` with `tree`/`old`/`new`/
     * `removed`/`guard`, or `SOURCE_EMPTY`/`SOURCE_UNREADABLE`), so the caller decides how to report
     * it.
     *
     * `$force` skips the bridge's delete guard only - never its source check: the guard is the
     * bridge refusing a rebuild that would delete most of a tree, and the app asks the administrator
     * before it sends `true` ({@see \OCA\Pages\Controller\BuildController::build()}).
     *
     * @param list<array{uid:string,area:string}> $authors
     * @return array<string,mixed>
     */
    public function mirror(array $authors = [], bool $build = true, bool $buildOnlinepreview = false, bool $dryRun = false, bool $force = false): array
    {
        return $this->authorDelta([
            'op' => 'mirror',
            'authors' => array_values($authors),
            'build' => $build,
            'build_onlinepreview' => $buildOnlinepreview,
            'dry_run' => $dryRun,
            'force' => $force,
        ]);
    }

    /**
     * The published bytes of one author target, read back out of the commit tree:
     * `200 {ok, target, bytes, content_base64}`.
     *
     * It is the only copy left when an author deleted the source of a page that is on the site, and
     * the reason it is base64 (not text) is that an author's tree holds images exactly like pages. The
     * route is read-only on the bridge: no mirror, no build, no commit, so asking for a page can never
     * move the site. Above the bridge's 5 MB cap it answers 413, and a target the commit tree does not
     * hold answers 404 - both are turned into the app's own wording here, because the caller's only
     * useful answer is "the transfer cannot happen, here is why".
     *
     * @param string $target site-relative target (`<author_root>/<uid>/<file>`)
     * @return array{target:string,bytes:int,content:string}
     * @throws BridgeException 404/413 as the specific transfer failure, everything else as itself
     */
    public function publishedContent(string $target): array
    {
        $clean = ltrim(trim(str_replace('\\', '/', $target)), '/');
        if ($clean === '') {
            throw new BridgeException($this->l10n->t('The published content could not be read back for the transfer.'), 'NOT_FOUND', [
                'target' => $target,
            ], 404);
        }
        // per segment, like fetchPreview(): a '/' stays a separator and a '#' or '?' in a file name
        // reaches the bridge as part of the name, not as a fragment or a second parameter
        $query = implode('/', array_map('rawurlencode', explode('/', $clean)));
        // read-only and lock-free: one file at most PUBLISHED_CONTENT_MAX_BYTES (5 MB) is read out of
        // the commit tree, so the 30 s default is the right timeout, not the lock-bound one
        try {
            $answer = $this->request('GET', '/site/published-content?target=' . $query);
        } catch (BridgeException $e) {
            if ($e->getHttpStatus() === 413 || $e->getBridgeCode() === 'TOO_LARGE') {
                throw new BridgeException(
                    $this->l10n->t('The file is too large to transfer.'),
                    'TOO_LARGE',
                    $e->getDetails() + ['target' => $clean],
                    413,
                );
            }
            if ($e->getHttpStatus() === 404 || $e->getBridgeCode() === 'NOT_FOUND') {
                throw new BridgeException(
                    $this->l10n->t('The published content could not be read back for the transfer.'),
                    'NOT_FOUND',
                    $e->getDetails() + ['target' => $clean],
                    404,
                );
            }
            throw $e;
        }
        $encoded = $answer['content_base64'] ?? null;
        $content = is_string($encoded) ? base64_decode($encoded, true) : false;
        if (!is_string($content)) {
            // a bridge that answers the documented shape always sends usable base64; this is the
            // answer to a bridge whose route is not the one this app expects
            throw new BridgeException(
                $this->l10n->t('The published content could not be read back for the transfer.'),
                'BAD_RESPONSE',
                ['target' => $clean],
            );
        }

        return [
            'target' => (string)($answer['target'] ?? $clean),
            'bytes' => is_numeric($answer['bytes'] ?? null) ? (int)$answer['bytes'] : strlen($content),
            'content' => $content,
        ];
    }

    /**
     * Move the author content directory through its own route.
     *
     * The bridge validates the shape and, unless ``$confirm`` is true, answers ``409
     * CONFIRM_REQUIRED`` for a directory that already exists inside the site, so the caller shows a
     * confirmation first. The move carries the content: every target under the old prefix in
     * ``online/`` and ``pending/`` is rewritten under the new name before the new root is adopted, and
     * the commit and review trees are rebuilt afterwards (the preview tree's author area is the app's
     * overwrite, right after this call). It is all or nothing - a file that cannot be moved
     * puts the files that did move back, keeps the old root in force and raises the bridge's ``409
     * AUTHOR_ROOT_MOVE_FAILED`` (its details carry ``moved`` / ``failed`` / ``remaining`` /
     * ``rollback_failed``). The answer is passed through whole: ``changed``, ``exists``, ``moved`` and
     * ``work_rebuild`` are the bridge's own report, and nothing here trims it.
     *
     * The move is synchronous file-by-file work - read the old target, write the new one and fsync it,
     * delete the old one - so its duration grows with how much ``online/`` and ``pending/`` hold, and
     * every write is a separate fsync. It already outlasts the 30 s default at a few dozen files. That
     * timeout is not a harmless retry either: the bridge goes on moving after the client gives up, so
     * the app reports the call as ``UNREACHABLE`` (HTTP 503) although the move may well have completed,
     * and it rolls its own root back over a move that succeeded. The endpoint holds the site lock for
     * the whole move, so this uses {@see self::lockBoundTimeout()} like {@see self::pullSite()}: the
     * same upper bound the bridge's own lock window gives, instead of a constant that a normal-sized
     * site can exceed.
     *
     * @return array<string,mixed>
     */
    public function setAuthorRoot(string $root, bool $confirm = false): array
    {
        $body = json_encode(['author_root' => $root, 'confirm' => $confirm]);
        return $this->request(
            'POST',
            '/site/author-root',
            ['Content-Type' => 'application/json'],
            $body === false ? '{}' : $body,
            $this->lockBoundTimeout(),
        );
    }

    /**
     * Fast-forwards from the remote and packages the pulled HEAD for the source replacement.
     *
     * The bridge holds the site lock for the pull and the archive, and waits `build_timeout_s + 30`
     * for it, so this uses {@see self::lockBoundTimeout()} - a pull stuck behind a build must not be
     * reported as failed while the bridge goes on to replace the tree.
     *
     * @return array<string,mixed>
     */
    public function pullSite(): array
    {
        return $this->request(
            'POST',
            '/site/pull',
            [],
            null,
            $this->lockBoundTimeout(),
        );
    }

    /**
     * The facts the Pull confirmation needs before anything is replaced: how many commits the remote
     * is ahead, how many local files are unpublished, and the head the commit tree is at. Read-only,
     * but it does fetch - that is the only way the ahead count can be the remote's own.
     *
     * @return array<string,mixed>
     */
    public function pullPreview(): array
    {
        // read-only and lock-free on the bridge (its own fetch gives up after 15 s), so the bounded
        // transfer timeout is enough: a lock-bound one would only hold the confirmation dialog open
        return $this->request('GET', '/site/pull/preview', [], null, self::BUILD_TIMEOUT);
    }

    /**
     * The tar.gz of the pulled **site source**, produced by the last pull.
     *
     * This is how the remote's content reaches the Nextcloud side: the app may not read the bridge
     * host's filesystem (and under snap it cannot), so the replacement travels over the same signed
     * channel as everything else, as one file whose sha256 the pull answer already named.
     *
     * The bridge archives the repository's **flat** tree (its root is the site itself: `_config.yml`,
     * `_layouts/`, …) minus the paths this instance knows as published (`online/`): the archive is
     * written into the site's `source/` folder, and an online page must not become the
     * administrator's skeleton. The pull answer reports `source_only: true`; a page published
     * elsewhere and unknown to this instance cannot be told apart and travels in the archive.
     */
    public function siteArchive(): string
    {
        [$status, $body] = $this->signedRequest('GET', '/site/archive', [], null, self::BUILD_TIMEOUT);
        if ($status !== 200) {
            // the bridge answers JSON on an error; decode() raises the structured exception
            $this->decode($status, $body);
            throw new BridgeException(
                $this->l10n->t('The bridge did not return the pulled archive (HTTP %s)', [(string)$status]),
                'BAD_RESPONSE',
                ['status' => $status],
            );
        }

        return $body;
    }

    /**
     * Mirror (unless the caller asks the bridge to skip it) and build, under the site lock.
     *
     * `do_build` waits `build_timeout_s + 30` for `runtime.lock` before it mirrors and builds, so the
     * caller has to outlive that wait - {@see self::lockBoundTimeout()}. The build itself then runs on
     * top of it (a run that has to install dependencies first can take longer still, a known limit of
     * this formula): the timeout is never below the 5 minutes it had before, and it grows with the
     * pushed `build_timeout_s`, so a site configured for slow builds is not cut off at a constant.
     *
     * @return array<string,mixed>
     */
    public function build(bool $force = false): array
    {
        $body = json_encode(['force' => $force]);
        return $this->request(
            'POST',
            '/site/build',
            ['Content-Type' => 'application/json'],
            $body === false ? '{}' : $body,
            $this->lockBoundTimeout(),
        );
    }

    /**
     * POST /site/touch: the "configuration was pushed, rebuild soon" ping. `syncAuthorSources()` calls
     * it after a save so the bridge mirrors and rebuilds once its debounce window has elapsed.
     */
    public function notify(): array
    {
        return $this->request('POST', '/site/touch');
    }

    /** @return array<string,mixed> */
    public function diff(): array
    {
        return $this->request('GET', '/site/diff');
    }

    /**
     * Commit everything the commit tree holds and push when a remote is configured.
     *
     * There is no path list any more: the commit tree is assembled from `source/` + `online/`, and a
     * page enters `online/` only through an approval (`publish` op). What a publish may commit is
     * therefore decided by the folder, not by a `stage_paths` array this app would have to reconstruct
     * - the removal of a page is the absence of its file from `online/` on the next assembly.
     *
     * A publish waits for the same site lock a build or an author delta holds, then mirrors, stages,
     * commits and maybe pushes: all of that runs after a `build_timeout_s + 30` lock window the bridge
     * is willing to sit through, so the request uses {@see self::lockBoundTimeout()} and not the fixed
     * 5 minutes that gave up first.
     *
     * The answer carries `remote_configured` and `pushed`, and a structured `push_error`
     * (`{code, ahead, stderr}`) when a push was attempted and failed. A push failure is **not** an
     * exception here: the commit already happened, and the caller decides how to report it.
     *
     * @return array<string,mixed>
     */
    public function publish(string $message): array
    {
        $json = json_encode(['message' => $message], JSON_UNESCAPED_UNICODE);
        return $this->request(
            'POST',
            '/site/publish',
            ['Content-Type' => 'application/json'],
            $json === false ? '{}' : $json,
            $this->lockBoundTimeout(),
        );
    }

    /** @return array<string,mixed> */
    public function log(int $tail = 0): array
    {
        $query = $tail > 0 ? '?tail=' . $tail : '';
        return $this->request('GET', '/site/log' . $query);
    }

    /**
     * Issues a short-lived preview token; the browser never sees the shared secret.
     *
     * @param list<string> $scopes
     * @return array<string,mixed>
     */
    public function createSession(int $ttl = 3600, array $scopes = ['preview']): array
    {
        // the bridge clamps the ttl as well; this only avoids absurd values
        $ttl = max(60, min($ttl, 12 * 3600));
        $query = http_build_query(['ttl' => $ttl, 'scope' => implode(',', $scopes)]);
        return $this->request('GET', '/site/session?' . $query);
    }

    /**
     * Reads the build output through the bridge (used by PreviewController); it uses the same
     * signed channel, so it gets the loopback fallback too.
     *
     * The route keeps a {token} segment to match the browser-facing URL shape, but this call is
     * authenticated by the bearer secret, so the segment is only a placeholder.
     *
     * `$tree` selects the bridge's build output: `preview` (the published tree, the default) or
     * `onlinepreview` (the review tree with the pending candidates laid over it). Only a non-default
     * tree adds a query at all, so the ordinary preview request stays byte for byte what it was; the
     * value is query-encoded and the path segments are already encoded above.
     *
     * @return array{status:int, body:string, contentType:string}
     */
    public function fetchPreview(string $path, string $tree = 'preview'): array
    {
        // The path arrives decoded (Nextcloud hands the route segment over as real UTF-8), and an HTTP
        // request target may only carry ASCII: a Chinese filename or a \x27#\x27/\x27?\x27 in a name went out raw,
        // reached the bridge as mojibake and was answered with 404. Encode per segment so \x27/\x27 stays a
        // separator; the bridge unquotes exactly once on its side.
        $encoded = implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
        $target = '/preview/token/' . $encoded;
        // the default carries no `?tree=` at all: an existing request must not change shape
        if ($tree !== 'preview') {
            $target .= '?' . http_build_query(['tree' => $tree]);
        }
        // a build-output file is served from disk without a lock or a graph of work behind it: the
        // default 30 s keeps a page preview from ever waiting on the lock-bound budget
        [$status, $body, $responseHeaders] = $this->signedRequest('GET', $target);
        return [
            'status' => $status,
            'body' => $body,
            'contentType' => $responseHeaders['content-type'] ?? '',
        ];
    }
}
