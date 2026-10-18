<?php

declare(strict_types=1);

namespace OCA\Pages\Service;

/**
 * Pairing with a fresh pages-bridge, and secret rotation afterwards.
 *
 * The app is the one generating both secrets, which is what removes the "copy the shared_secret out
 * of the TOML into Nextcloud" step: install.sh prints a one-time code, the administrator types only
 * that code, and the data directory is discovered from Nextcloud's own configuration.
 *
 * Nothing here is stored before the bridge has confirmed the pairing: a bridge that never accepted
 * the request must not leave the app looking paired.
 */
class PairingService
{
    public function __construct(
        private SiteRegistry $registry,
        private BridgeClient $bridge,
    ) {
    }

    /** 32 random bytes as hex, the shape every bridge secret has. */
    public function newSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Pairs with the bridge at $url and stores the secrets it accepted.
     *
     * @return array{url:string,version:string,protocol:int,restart_required:bool,revision:int,fingerprints:array{shared_secret:string,session_signing_secret:string}}
     */
    public function pair(string $url, string $code): array
    {
        $url = rtrim(trim($url), '/');
        $global = $this->registry->getGlobal();
        $ncDataRoot = $this->bridge->discoverNcDataRoot();
        if ($ncDataRoot === '') {
            throw new BridgeException(
                'Nextcloud did not report a data directory (config.php: datadirectory)',
                'NO_DATA_ROOT',
            );
        }
        // a cross-machine bridge needs a bind-mounted path; fail with the sent value, not a guess
        if (isset($global['nc_data_root'])) {
            $ncDataRoot = (string)$global['nc_data_root'];
        }

        $shared = $this->newSecret();
        $signing = $this->newSecret();
        // the site is handed over too when it is already configured, so a fresh bridge needs no
        // second call; `global` carries only the parameters the administrator actually set. The
        // author and preview directories travel with the site, exactly as in every later push.
        $site = $this->registry->getSite();
        $answer = $this->bridge->pair($url, $code, $shared, $signing, $ncDataRoot, [
            'global' => $global,
            'site' => $site === null ? null : $this->registry->siteForBridge($site),
        ]);

        // only now are the secrets worth storing: the bridge has accepted them
        $this->registry->setBridgeUrl($url);
        $this->registry->setSharedSecret($shared);
        $this->registry->setSigningSecret($signing);

        return [
            'url' => $url,
            'version' => (string)($answer['version'] ?? ''),
            'protocol' => (int)($answer['protocol'] ?? BridgeClient::PROTOCOL),
            'restart_required' => (bool)($answer['restart_required'] ?? false),
            'revision' => (int)($answer['revision'] ?? $this->registry->getRevision()),
            'fingerprints' => [
                'shared_secret' => $this->registry->secretFingerprint(SiteRegistry::KEY_SHARED_SECRET),
                'session_signing_secret' => $this->registry->secretFingerprint(SiteRegistry::KEY_SIGNING_SECRET),
            ],
        ];
    }
}
