<?php

declare(strict_types=1);

namespace OCA\Pages\Service;

/**
 * Verifies the short-lived preview tokens issued by the bridge: an independent PHP implementation
 * that must stay byte-compatible with `pages_bridge/session.py` (cross-checked by occ pages:selftest).
 */
class SessionVerifier
{
    /** Must match pages_bridge.session.VERSION: 2 = single site, no site claim in the payload. */
    public const VERSION = 2;

    public function __construct(
        private SiteRegistry $registry,
        private \OCP\IL10N $l10n,
    ) {
    }

    /**
     * @return array<string,mixed> the verified payload
     * @throws BridgeException if any check fails
     */
    public function verify(string $token, string $scope = 'preview'): array
    {
        $secret = $this->registry->getSigningSecret();
        if ($secret === '') {
            throw new BridgeException($this->l10n->t('Session signing secret is not configured'), 'NOT_CONFIGURED');
        }
        if ($token === '' || substr_count($token, '.') !== 1) {
            throw new BridgeException($this->l10n->t('Malformed preview token'), 'INVALID_SESSION');
        }
        [$payloadB64, $signature] = explode('.', $token, 2);

        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $payloadB64, $secret, true)), '+/', '-_'), '=');
        if (!hash_equals($expected, $signature)) {
            throw new BridgeException($this->l10n->t('Preview token signature does not match'), 'INVALID_SESSION');
        }

        $json = $this->base64UrlDecode($payloadB64);
        $payload = $json === null ? null : json_decode($json, true);
        if (!is_array($payload) || ($payload['v'] ?? null) !== self::VERSION) {
            throw new BridgeException($this->l10n->t('Preview token payload cannot be parsed'), 'INVALID_SESSION');
        }
        if ((int)($payload['exp'] ?? 0) <= time()) {
            throw new BridgeException($this->l10n->t('Preview token has expired; reload the page to get a new one'), 'SESSION_EXPIRED');
        }
        // single site: no site claim to compare, the scope is the only claim left besides exp
        $scopes = is_array($payload['scope'] ?? null) ? $payload['scope'] : [];
        if (!in_array($scope, $scopes, true)) {
            throw new BridgeException($this->l10n->t('Preview token lacks the required scope'), 'INVALID_SESSION', ['need' => $scope]);
        }
        return $payload;
    }

    private function base64UrlDecode(string $text): ?string
    {
        $padded = $text . str_repeat('=', (4 - strlen($text) % 4) % 4);
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }
}
