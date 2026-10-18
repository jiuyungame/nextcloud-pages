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

    private const TIMEOUT = 30;
    private const BUILD_TIMEOUT = 300;

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
     * `$authors` is the multi-author extension: the list travels **inside** `site`, because it
     * describes where that one site takes its extra sources from. An absent or empty list is not sent
     * at all, which is what keeps an install without author areas on the single-source behaviour.
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
     * Fast-forwards from the remote and mirrors the changes back into the Nextcloud source.
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
            self::BUILD_TIMEOUT,
        );
    }

    /** @return array<string,mixed> */
    public function build(bool $force = false): array
    {
        $body = json_encode(['force' => $force]);
        return $this->request(
            'POST',
            '/site/build',
            ['Content-Type' => 'application/json'],
            $body === false ? '{}' : $body,
            self::BUILD_TIMEOUT,
        );
    }

    /** Called after a save: the bridge mirrors and rebuilds once its debounce window has elapsed. */
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
     * Commit and push. `$stagePaths` names what may go into the commit (relative to the work copy):
     * with several authors in one work copy, the commit has to be limited to what was reviewed, or an
     * unfinished draft would ride along. Omit it to stage everything, as before.
     *
     * @param list<string>|null $stagePaths
     * @return array<string,mixed>
     */
    public function publish(string $message, ?array $stagePaths = null): array
    {
        $body = ['message' => $message];
        if ($stagePaths !== null) {
            $body['stage_paths'] = array_values($stagePaths);
        }
        $json = json_encode($body, JSON_UNESCAPED_UNICODE);
        return $this->request(
            'POST',
            '/site/publish',
            ['Content-Type' => 'application/json'],
            $json === false ? '{}' : $json,
            self::BUILD_TIMEOUT,
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
     * @return array{status:int, body:string, contentType:string}
     */
    public function fetchPreview(string $path): array
    {
        $target = '/preview/token/' . ltrim($path, '/');
        [$status, $body, $responseHeaders] = $this->signedRequest('GET', $target);
        return [
            'status' => $status,
            'body' => $body,
            'contentType' => $responseHeaders['content-type'] ?? '',
        ];
    }
}
