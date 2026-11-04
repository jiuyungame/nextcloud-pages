<?php

declare(strict_types=1);

namespace OCA\Pages\Service;

use OCP\IURLGenerator;

/**
 * Same-origin reverse proxy for the build output: fetch, rewrite root-absolute URLs, inject the
 * preview script. The rewrite touches the stream only, never the output on disk; the iframe cannot
 * load the bridge directly because the page is HTTPS while the bridge is plain HTTP.
 */
class PreviewProxy
{
    /**
     * The build output a preview reads. `preview` is the tree the published site is built from - the
     * default, and the only one that existed before reviewer previews. `onlinepreview` is the bridge's
     * review tree: the published content with the frozen, not-yet-approved candidates laid over it
     * (`GET /preview/{token}/{path}?tree=onlinepreview` on the bridge).
     */
    public const TREE_PREVIEW = 'preview';
    public const TREE_ONLINEPREVIEW = 'onlinepreview';

    /** Only these content types are rewritten. */
    private const REWRITABLE = ['text/html', 'application/xhtml+xml', 'text/css'];

    /**
     * Injected into preview pages: keeps the scroll position and reloads on the parent's message.
     * `{{nonce}}` is replaced with the CSP nonce of the current response.
     */
    private const INJECT = <<<'JS'
<script data-pages-preview="1" nonce="{{nonce}}">
(function () {
	var KEY = 'pages-preview-scroll';
	try {
		var saved = sessionStorage.getItem(KEY);
		if (saved !== null) { window.scrollTo(0, parseInt(saved, 10) || 0); }
	} catch (e) { /* opaque origin, ignore */ }
	window.addEventListener('scroll', function () {
		try { sessionStorage.setItem(KEY, String(window.scrollY || window.pageYOffset || 0)); } catch (e) {}
	}, { passive: true });
	window.addEventListener('message', function (event) {
		if (event && event.data && event.data.type === 'pages:reload') { location.reload(); }
	});
})();
</script>
JS;

    public function __construct(
        private BridgeClient $bridge,
        private IURLGenerator $urlGenerator,
    ) {
    }

    /** Base URL of the preview iframe. */
    public function baseFor(string $token): string
    {
        // do not hardcode /apps/...: without pretty URLs the real prefix is /index.php/apps/pages
        $root = $this->urlGenerator->linkToRoute('pages.page.index');
        return rtrim($root, '/') . '/preview/' . rawurlencode($token) . '/';
    }

    /**
     * Fetches one file of the selected build output and rewrites it for the iframe.
     *
     * `$tree` chooses the bridge tree: {@see self::TREE_PREVIEW} (the published output, the default)
     * or {@see self::TREE_ONLINEPREVIEW} (the review tree). The rewriting is the same for both - it
     * maps the build's root-absolute URLs onto the preview base - so a candidate page is served with
     * its own bytes and the same injected scroll/reload script. Callers are expected to have checked
     * who may read that tree: this class makes no permission decision.
     *
     * The selector follows the request that named it, not what that page pulls in: a rewritten
     * root-absolute URL carries no query, and a link followed inside the frame loses it as well, so
     * those sub-requests are read from the published tree. The candidate page itself - the thing a
     * reviewer decides about - is the one served from the review tree.
     *
     * @return array{status:int, body:string, contentType:string}
     */
    public function fetch(
        string $path,
        string $base,
        ?string $siteUrl = null,
        string $nonce = '',
        string $tree = self::TREE_PREVIEW,
    ): array
    {
        $result = $this->bridge->fetchPreview($path, $tree);
        $contentType = strtolower(trim(explode(';', $result['contentType'] ?: 'application/octet-stream')[0]));

        if ($result['status'] === 200 && in_array($contentType, self::REWRITABLE, true)) {
            if ($contentType === 'text/css') {
                $result['body'] = $this->rewriteCss($result['body'], $base);
            } else {
                $result['body'] = $this->rewriteHtml($result['body'], $base, $siteUrl, $nonce);
            }
            $result['contentType'] = $contentType;
        }
        return ['status' => $result['status'], 'body' => $result['body'], 'contentType' => $contentType];
    }

    public function rewriteHtml(string $body, string $base, ?string $siteUrl = null, string $nonce = ''): string
    {
        // quoted URL attributes; (?!//) skips protocol-relative and (?!/) already-absolute URLs
        $body = preg_replace_callback(
            '#(\s(?:href|src|action|poster|data-src|data-background|data-original)\s*=\s*)(["\'])(/(?!/)[^"\']*)\2#i',
            fn (array $m): string => $m[1] . $m[2] . $this->rewriteUrl($m[3], $base) . $m[2],
            $body,
        ) ?? $body;

        // every srcset candidate, with its optional 1x / 2w descriptor
        $body = preg_replace_callback(
            '#(\ssrcset\s*=\s*)(["\'])([^"\']*)\2#i',
            function (array $m) use ($base): string {
                $candidates = array_map(function (string $candidate) use ($base): string {
                    $candidate = trim($candidate);
                    if ($candidate === '') {
                        return '';
                    }
                    if (preg_match('#^(\S+)(\s+.+)?$#', $candidate, $parts) !== 1) {
                        return $candidate;
                    }
                    return $this->rewriteUrl($parts[1], $base) . ($parts[2] ?? '');
                }, explode(',', $m[3]));
                return $m[1] . $m[2] . implode(', ', $candidates) . $m[2];
            },
            $body,
        ) ?? $body;

        // url(/...) inside <style> blocks and style="..." attributes
        $body = preg_replace_callback(
            '#(<style\b[^>]*>)(.*?)(</style>)#is',
            fn (array $m): string => $m[1] . $this->rewriteCss($m[2], $base) . $m[3],
            $body,
        ) ?? $body;
        $body = preg_replace_callback(
            '#(\sstyle\s*=\s*)(["\'])([^"\']*)\2#i',
            function (array $m) use ($base): string {
                // generators write &quot; / &#39; for the quotes, which hides the leading slash from the
                // rewriter: decode first, rewrite, then encode the double quotes again
                $decoded = str_replace(['&quot;', '&#39;', '&#x27;'], ['"', "'", "'"], $m[3]);
                $rewritten = $this->rewriteCss($decoded, $base);
                return $m[1] . $m[2] . str_replace('"', '&quot;', $rewritten) . $m[2];
            },
            $body,
        ) ?? $body;

        // the site's own absolute domain, emitted by some generators
        if ($siteUrl !== null && $siteUrl !== '') {
            $quoted = preg_quote(rtrim($siteUrl, '/'), '#');
            $body = preg_replace_callback(
                '#(["\'])' . $quoted . '(/[^"\']*)?(["\'])#i',
                fn (array $m): string => $m[1] . $base . ltrim($m[2] ?? '', '/') . $m[3],
                $body,
            ) ?? $body;
        }

        // a nonce is the only way to allow inline <script> in current Nextcloud; the site's own
        // inline scripts (MathJax config, for instance) would be blocked otherwise.
        // Known gap: inline event handlers (onclick="...") need 'unsafe-inline' and stay blocked.
        if ($nonce !== '') {
            $body = preg_replace_callback(
                '#<script\b([^>]*)>#i',
                static function (array $m) use ($nonce): string {
                    if (stripos($m[1], 'nonce=') !== false) {
                        return $m[0];
                    }
                    return '<script nonce="' . $nonce . '"' . $m[1] . '>';
                },
                $body,
            ) ?? $body;
        }

        return $this->inject($body, str_replace('{{nonce}}', $nonce, self::INJECT));
    }

    public function rewriteCss(string $body, string $base): string
    {
        $body = preg_replace_callback(
            '#url\(\s*(["\']?)(/(?!/)[^)"\']*)\1\s*\)#i',
            fn (array $m): string => 'url(' . $m[1] . $this->rewriteUrl($m[2], $base) . $m[1] . ')',
            $body,
        ) ?? $body;

        return preg_replace_callback(
            '#(@import\s+)(["\'])(/(?!/)[^"\']*)\2#i',
            fn (array $m): string => $m[1] . $m[2] . $this->rewriteUrl($m[3], $base) . $m[2],
            $body,
        ) ?? $body;
    }

    private function rewriteUrl(string $url, string $base): string
    {
        if ($url === '' || $url[0] !== '/') {
            return $url;                       // relative: already resolves under the preview prefix
        }
        if (str_starts_with($url, '//')) {
            return $url;                       // protocol-relative: an external resource
        }
        return $base . ltrim($url, '/');
    }

    private function inject(string $html, string $snippet): string
    {
        if (str_contains($html, 'data-pages-preview="1"')) {
            return $html;                      // idempotent
        }
        $position = stripos($html, '</body>');
        if ($position === false) {
            return $html . $snippet;
        }
        return substr($html, 0, $position) . $snippet . substr($html, $position);
    }
}
