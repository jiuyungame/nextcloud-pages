<?php

declare(strict_types=1);

namespace OCA\Pages\Controller;

use OCA\Pages\AppInfo\Application;
use OCA\Pages\Http\RawResponse;
use OCA\Pages\Service\BridgeException;
use OCA\Pages\Service\PreviewProxy;
use OCA\Pages\Service\SessionVerifier;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\DataResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Same-origin reverse proxy of the build output, used as the preview iframe source.
 * The browser never sees the shared secret: the short-lived signed token is the credential.
 */
class PreviewController extends BaseController
{
    public function __construct(
        IRequest $request,
        IUserSession $userSession,
        IGroupManager $groupManager,
        \OCA\Pages\Service\SiteRegistry $registry,
        \OCA\Pages\Service\FileService $files,
        \OCA\Pages\Service\BridgeClient $bridge,
        private SessionVerifier $sessions,
        private PreviewProxy $preview,
        private \OCP\Security\ISecureRandom $random,
        IL10N $l10n,
    ) {
        parent::__construct(Application::APP_ID, $request, $userSession, $groupManager, $registry, $files, $bridge, $l10n);
    }

    /**
     * Issues a short-lived preview token for the iframe; the browser never sees the shared secret.
     * Open to every signed-in user: the preview itself needs no capability. The token carries the
     * `preview` scope only — publishing is authorized per request by the publish capability and never
     * by a token, so an iframe URL stays a read-only credential even if it is copied elsewhere.
     */
    #[NoAdminRequired]
    public function session(): DataResponse
    {
        [$denied] = $this->siteContext('view');
        if ($denied !== null) {
            return $denied;
        }
        $ttl = (int)$this->request->getParam('ttl', 3600);
        $ttl = max(60, min($ttl, 12 * 3600));
        try {
            $issued = $this->bridge->createSession($ttl, ['preview']);
        } catch (\Throwable $e) {
            return $this->translate($e) ?? throw $e;
        }
        return new DataResponse([
            'token' => $issued['token'] ?? '',
            'exp' => $issued['exp'] ?? 0,
            'ttl' => $issued['ttl'] ?? $ttl,
            'base' => $this->preview->baseFor((string)($issued['token'] ?? '')),
        ]);
    }

    /**
     * Proxies the build output. #[NoCSRFRequired]: a plain iframe GET carries no requesttoken, and
     * the short-lived signed token is the real credential for this route. #[NoAdminRequired]: the
     * preview is open to every signed-in user; the token check below stays the gate.
     *
     * `?tree=` selects which build output is read: absent or `preview` is the published site (the
     * author preview, unchanged), `onlinepreview` is the bridge's review tree - the published content
     * with the frozen candidates of pending requests laid over it, which is what a reviewer compares
     * against the live page before deciding. The review tree is *unapproved* content, so it is
     * restricted to reviewers below; the token is the same read-only `preview` token either way.
     */
    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function serve(string $token, string $path = 'index.html'): Http\Response
    {
        // empty or directory paths become index.html (the route requirement for path is `.*`)
        if ($path === '' || str_ends_with($path, '/')) {
            $path .= 'index.html';
        }

        $denied = $this->requireUser();
        if ($denied !== null) {
            return $this->plainError((int)$denied->getStatus(), (string)(($denied->getData()['message'] ?? $this->l10n->t('Access denied'))));
        }

        try {
            // protocol 3: the token payload carries the scope only, there is no site claim left
            $this->sessions->verify($token, 'preview');
        } catch (BridgeException $e) {
            return $this->plainError($e->getBridgeCode() === 'SESSION_EXPIRED' ? 401 : 403, $e->getMessage(), $e);
        }

        // `?tree=` selects the build output. Only the exact bridge name selects the review tree; any
        // other value (a typo, a repeated or array parameter, a client that predates the selector) is
        // ignored and the published tree is served - the parameter can add one tree, never widen
        // access, and an iframe that arrived with a misspelled name is better off with the page it
        // would have got anyway than with an error page. The explicit comparison below is therefore
        // the *only* way into unapproved content, and the check after it is the one that guards it.
        $requested = $this->request->getParam('tree', PreviewProxy::TREE_PREVIEW);
        $tree = is_string($requested) && $requested === PreviewProxy::TREE_ONLINEPREVIEW
            ? PreviewProxy::TREE_ONLINEPREVIEW
            : PreviewProxy::TREE_PREVIEW;

        // The review tree holds content nobody has approved yet: only a reviewer may look at it. The
        // token alone is not enough, because every signed-in user can ask for one (it is read-only and
        // carries the `preview` scope only, never a permission).
        if ($tree === PreviewProxy::TREE_ONLINEPREVIEW && !$this->canReview()) {
            return $this->plainError(403, $this->l10n->t('You do not have permission to review or publish articles.'));
        }

        // one site: no id has to be read out of the token, the site is looked up directly
        $site = $this->registry->getSite();
        if ($site === null) {
            return $this->plainError(404, $this->l10n->t('No site is configured yet'));
        }

        $base = $this->preview->baseFor($token);
        /** @var array<string,mixed> $recipe */
        $recipe = is_array($site['recipe'] ?? null) ? $site['recipe'] : [];
        $overrides = is_array($recipe['overrides'] ?? null) ? $recipe['overrides'] : [];
        $siteUrl = isset($overrides['url']) ? (string)$overrides['url'] : null;

        $nonce = base64_encode($this->random->generate(16));

        try {
            $result = $this->preview->fetch($path, $base, $siteUrl, $nonce, $tree);
        } catch (\Throwable $e) {
            return $this->plainError(502, $e->getMessage(), $e instanceof BridgeException ? $e : null);
        }

        if ($result['status'] !== 200) {
            // missing build output gets a readable page instead of a blank one
            return $this->notFoundPage($path, $result['status']);
        }

        $response = new RawResponse($result['body'], $result['contentType'] ?: 'application/octet-stream');
        $response->setStatus(200);
        $response->setContentSecurityPolicy($this->previewCsp($nonce));
        return $response;
    }

    /**
     * CSP for the preview route only: inline scripts are allowed by nonce, remote assets by domain
     * wildcards (the site loads fonts, icons and scripts from CDNs).
     */
    private function previewCsp(string $nonce = ''): ContentSecurityPolicy
    {
        $csp = new ContentSecurityPolicy();
        if ($nonce !== '') {
            $csp->useJsNonce($nonce);
        }
        $csp->allowInlineStyle(true);
        $csp->addAllowedScriptDomain('*');
        $csp->addAllowedStyleDomain('*');
        $csp->addAllowedFontDomain('*');
        $csp->addAllowedImageDomain('*');
        $csp->addAllowedMediaDomain('*');
        $csp->addAllowedConnectDomain('*');
        return $csp;
    }

    private function plainError(int $status, string $message, ?BridgeException $e = null): RawResponse
    {
        $html = '<!doctype html><meta charset="utf-8">'
            . '<style>body{font:14px/1.6 system-ui,sans-serif;padding:24px;color:#222}'
            . 'code{background:#f4f4f4;padding:2px 5px;border-radius:3px}'
            . '.h{font-size:16px;font-weight:600;margin-bottom:8px}</style>'
            . '<div class="h">' . htmlspecialchars($this->l10n->t('Pages preview unavailable'), ENT_QUOTES) . '</div>'
            . '<p>' . htmlspecialchars($message, ENT_QUOTES) . '</p>'
            . ($e !== null && $e->getDetails() !== []
                ? '<p><code>' . htmlspecialchars(json_encode($e->getDetails(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '', ENT_QUOTES) . '</code></p>'
                : '')
            . '<p style="opacity:.6">' . htmlspecialchars($this->l10n->t('Once the token expires, reload the page to get a new one.'), ENT_QUOTES) . '</p>';
        $response = new RawResponse($html, 'text/html');
        $response->setStatus($status);
        $response->setContentSecurityPolicy($this->previewCsp());
        return $response;
    }

    private function notFoundPage(string $path, int $upstreamStatus): RawResponse
    {
        $html = '<!doctype html><meta charset="utf-8">'
            . '<style>body{font:14px/1.6 system-ui,sans-serif;padding:24px;color:#222}'
            . 'code{background:#f4f4f4;padding:2px 5px;border-radius:3px}h3{margin:0 0 8px}</style>'
            . '<h3>' . htmlspecialchars($this->l10n->t('The build output has no such file'), ENT_QUOTES) . '</h3>'
            . '<p><code>' . htmlspecialchars($path, ENT_QUOTES) . '</code> ' . htmlspecialchars($this->l10n->t('(bridge returned HTTP %s)', [(string)$upstreamStatus]), ENT_QUOTES) . '</p>'
            . '<p>' . htmlspecialchars($this->l10n->t('Usual causes: the site has not built successfully yet, or the link points to a page that did not exist at build time.'), ENT_QUOTES) . '</p>';
        $response = new RawResponse($html, 'text/html');
        $response->setStatus(404);
        $response->setContentSecurityPolicy($this->previewCsp());
        return $response;
    }
}
