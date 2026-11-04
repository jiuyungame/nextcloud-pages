<?php

declare(strict_types=1);

namespace OCA\Pages\Command;

use OCA\Pages\Service\BridgeClient;
use OCA\Pages\Service\BridgeException;
use OCA\Pages\Service\FileService;
use OCA\Pages\Service\PreviewProxy;
use OCA\Pages\Service\SessionVerifier;
use OCA\Pages\Service\SiteRegistry;
use OCP\Files\NotFoundException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ pages:selftest` — automation of the acceptance path: write through FileService, notify
 * the bridge, wait for the build, verify the output, delete and clean up.
 */
class SelfTest extends Command
{
    /**
     * The probe file must not be dot-prefixed: site generators ignore dot files, so it would be
     * mirrored but never built, and the test would not test what it is meant to test.
     */
    private const PROBE = 'pages-selftest-probe.md';

    public function __construct(
        private SiteRegistry $registry,
        private BridgeClient $bridge,
        private FileService $files,
        private PreviewProxy $preview,
        private SessionVerifier $sessions,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('pages:selftest')
            ->setDescription('End-to-end self-test: write a file in Nextcloud and verify it reaches the host working copy')
            ->addOption('timeout', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'seconds to wait', '30');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // one site: there is no id left to select, so the command takes no argument either
        $site = $this->registry->getSite();
        if ($site === null) {
            $output->writeln('<error>No site is configured yet.</error>');
            return 1;
        }

        // no hardcoded username fallback: report the missing field instead of passing by accident
        $user = (string)($site['nc_user'] ?? '');
        if ($user === '') {
            $output->writeln('<error>The site has no nc_user; fill it in under Admin settings → Pages → Site.</error>');
            return 1;
        }
        $ncPath = (string)($site['nc_path'] ?? '');
        $deadline = time() + max(5, (int)$input->getOption('timeout'));
        $failures = [];

        $output->writeln(sprintf('<info>site</info> %s  <info>user</info> %s', $ncPath, $user));
        // the site folder holds the three fixed subdirectories; the skeleton (and therefore a probe
        // page the build has to pick up) lives in `source/`
        $ncPath = rtrim($ncPath, '/') . '/' . \OCA\Pages\Service\SiteTrees::SOURCE;

        // ---- pure-function check: preview rewriting ----
        $failures = array_merge($failures, $this->checkRewriting($output));

        // ---- cross-language agreement of the signed token ----
        $failures = array_merge($failures, $this->checkTokenRoundTrip($output));

        // ---- baseline ----
        $before = $this->buildId();
        $output->writeln('baseline build_id = ' . $before);

        // ---- write through the Files API ----
        // YAML front matter is required: Jekyll copies a .md without it verbatim, so no output would be built
        $body = "---\n"
            . "layout: default\n"
            . "title: Pages self-test probe\n"
            . "---\n"
            . "# Pages self-test probe\n\n"
            . 'Created by occ pages:selftest at ' . date('c') . ", safe to delete.\n";
        $started = microtime(true);
        try {
            $written = $this->files->write($user, $ncPath, self::PROBE, $body, null, true);
            $output->writeln(sprintf(
                '<info>written</info> %s · ETag %s · %d bytes',
                self::PROBE,
                substr((string)$written['etag'], 0, 8),
                (int)$written['size'],
            ));
        } catch (\Throwable $e) {
            $output->writeln('<error>write failed: ' . $e->getMessage() . '</error>');
            return 1;
        }

        // ---- notify the bridge and wait for the build ----
        try {
            $this->bridge->notify();
        } catch (\Throwable $e) {
            // the notify is this command's push step, so it uses the same exit taxonomy as
            // `pages:push`: 2 when the bridge did not answer at all (retry), 1 for everything else
            $output->writeln('<error>bridge notify failed: ' . $e->getMessage() . '</error>');
            $this->cleanup($user, $ncPath, $output);
            return self::exitFor($e);
        }
        $after = $this->waitForBuild($before, $deadline);
        $elapsed = microtime(true) - $started;
        if ($after <= $before) {
            $failures[] = 'timed out, the bridge produced no new build';
            $output->writeln('<error>timeout: build_id is still ' . $after . '</error>');
        } else {
            $output->writeln(sprintf(
                '<info>built</info> build_id %d → %d in %.1f s (including debounce)',
                $before,
                $after,
                $elapsed,
            ));
        }

        // ---- is the probe in the build output? ----
        try {
            $suffix = pathinfo(self::PROBE, PATHINFO_FILENAME);
            $preview = $this->bridge->fetchPreview($suffix . '.html');
            if ($preview['status'] === 200 && str_contains($preview['body'], 'Pages self-test probe')) {
                $output->writeln('<info>output generated</info> ' . $suffix . '.html (' . strlen($preview['body']) . ' bytes)');
            } else {
                $failures[] = 'probe page not found in the preview (HTTP ' . $preview['status'] . ')';
                $output->writeln('<error>output check failed: HTTP ' . $preview['status'] . '</error>');
            }
        } catch (\Throwable $e) {
            $failures[] = 'output check error: ' . $e->getMessage();
            $output->writeln('<error>output check error: ' . $e->getMessage() . '</error>');
        }

        // ---- delete and verify the propagation ----
        try {
            $this->files->delete($user, $ncPath, self::PROBE);
            $this->bridge->notify();
            $removed = $this->waitForBuild($after, $deadline);
            $output->writeln($removed > $after
                ? '<info>deletion propagated</info> build_id = ' . $removed
                : '<comment>no new build after the deletion (may still be inside the debounce window)</comment>');
        } catch (\Throwable $e) {
            $failures[] = 'delete failed: ' . $e->getMessage();
            $output->writeln('<error>delete failed: ' . $e->getMessage() . '</error>');
        }

        if ($failures !== []) {
            $output->writeln('');
            foreach ($failures as $failure) {
                $output->writeln('<error>✗ ' . $failure . '</error>');
            }
            return 1;
        }
        $output->writeln('');
        $output->writeln('<info>✓ end-to-end self-test passed</info>');
        return 0;
    }

    /**
     * Preview rewriting is a pure function, so the samples can be asserted directly.
     *
     * @return list<string> failures
     */
    private function checkRewriting(OutputInterface $output): array
    {
        $base = '/index.php/apps/pages/preview/TOKEN/';
        $failures = [];
        $expect = function (string $what, bool $ok) use (&$failures, $output, $base): void {
            if (!$ok) {
                $failures[] = 'preview rewrite: ' . $what;
                $output->writeln('<error>✗ preview rewrite: ' . $what . '</error>');
            }
        };

        $html = '<html><head>'
            . '<link rel="stylesheet" href="/assets/main.css">'
            . '<script src="/assets/app.js"></script>'
            . '<style>.a{background:url(/images/bg.png)}</style>'
            . '</head><body>'
            . '<a href="/about.html">about</a>'
            . '<img src="/images/x.png" srcset="/images/x.png 1x, /images/x@2x.png 2x">'
            . '<div style="background:url(&quot;/images/y.png&quot;)"></div>'
            . '<a href="//cdn.example.com/lib.js">cdn</a>'
            . '<a href="relative.html">rel</a>'
            . '<a href="#top">anchor</a>'
            . '<a href="https://site.example.com/demo.html">abs</a>'
            . '</body></html>';
        // neutral sample domain: this self-test ships with the app, so no real site may appear here
        $out = $this->preview->rewriteHtml($html, $base, 'https://site.example.com');

        $expect('root-absolute href rewritten', str_contains($out, 'href="' . $base . 'about.html"'));
        $expect('root-absolute src rewritten', str_contains($out, 'src="' . $base . 'assets/app.js"'));
        $expect('both srcset candidates rewritten',
            str_contains($out, $base . 'images/x.png 1x') && str_contains($out, $base . 'images/x@2x.png 2x'));
        $expect('url() inside <style> rewritten', str_contains($out, 'url(' . $base . 'images/bg.png)'));
        $expect('url() inside inline style rewritten', str_contains($out, $base . 'images/y.png'));
        $expect('site absolute domain rewritten', str_contains($out, 'href="' . $base . 'demo.html"'));
        $expect('protocol-relative URL untouched', str_contains($out, 'href="//cdn.example.com/lib.js"'));
        $expect('relative path untouched', str_contains($out, 'href="relative.html"'));
        $expect('anchor untouched', str_contains($out, 'href="#top"'));
        $expect('preview script injected', str_contains($out, 'data-pages-preview="1"'));
        $expect('script injected before </body>',
            strpos($out, 'data-pages-preview') < strpos($out, '</body>'));
        $expect('idempotent: rewriting again does not inject twice',
            substr_count($this->preview->rewriteHtml($out, $base, 'https://site.example.com'), 'data-pages-preview') === 1);

        $css = '@import "/base.css";.a{background:url(/images/bg.png)}.b{background:url("/q.png")}'
            . '.c{background:url(data:image/png;base64,AAA)}';
        $cssOut = $this->preview->rewriteCss($css, $base);
        $expect('CSS @import rewritten', str_contains($cssOut, '@import "' . $base . 'base.css"'));
        $expect('CSS url() rewritten', str_contains($cssOut, 'url(' . $base . 'images/bg.png)'));
        $expect('CSS quoted url() rewritten', str_contains($cssOut, 'url("' . $base . 'q.png")'));
        $expect('data: URI untouched', str_contains($cssOut, 'url(data:image/png;base64,AAA)'));

        if ($failures === []) {
            $output->writeln('<info>preview rewrite</info> 15 assertions passed');
        }
        return $failures;
    }

    /**
     * A token issued by the bridge must be accepted by the PHP verifier: the cross-language drift check.
     * The former site assertions are gone with the single-site app (there is no second site id and no
     * site claim left in the payload), so the negative case is the remaining claim: the scope.
     *
     * @return list<string> failures
     */
    private function checkTokenRoundTrip(OutputInterface $output): array
    {
        try {
            $issued = $this->bridge->createSession(600, ['preview']);
            $token = (string)($issued['token'] ?? '');
            $payload = $this->sessions->verify($token, 'preview');
            $output->writeln(sprintf(
                '<info>token cross-check</info> issued by bridge → accepted by PHP (v%s scope=%s)',
                (string)($payload['v'] ?? '?'),
                implode(',', (array)($payload['scope'] ?? [])),
            ));
        } catch (\Throwable $e) {
            $output->writeln('<error>✗ token cross-check failed: ' . $e->getMessage() . '</error>');
            return ['token cross-check failed: ' . $e->getMessage()];
        }

        // negative case: the verifier must refuse a scope the token does not carry
        try {
            $this->sessions->verify($token, 'publish');
        } catch (BridgeException) {
            $output->writeln('<info>token cross-check</info> a scope the token does not carry is refused');
            return [];
        } catch (\Throwable $e) {
            return ['token scope cross-check errored: ' . $e->getMessage()];
        }
        $output->writeln('<error>✗ token cross-check: a preview-only token was accepted for the publish scope</error>');
        return ['token cross-check: a preview-only token was accepted for the publish scope'];
    }

    /**
     * The exit taxonomy `pages:push` uses, applied to a failure this command reports: `2` when the
     * bridge did not answer at all (nothing was decided, the run can simply be repeated), `1` for every
     * other failure - including a bridge that answered and refused, and a local check that did not hold.
     */
    private static function exitFor(\Throwable $e): int
    {
        return $e instanceof BridgeException && $e->isRetryable()
            ? PushConfig::EXIT_RETRYABLE
            : PushConfig::EXIT_FAILED;
    }

    private function buildId(): int
    {
        try {
            $status = $this->bridge->siteStatus();
        } catch (\Throwable) {
            return 0;
        }
        return (int)($status['last_build']['build_id'] ?? 0);
    }

    private function waitForBuild(int $baseline, int $deadline): int
    {
        $current = $baseline;
        while (time() < $deadline) {
            usleep(500_000);
            $current = $this->buildId();
            if ($current > $baseline) {
                return $current;
            }
        }
        return $current;
    }

    private function cleanup(string $user, string $ncPath, OutputInterface $output): void
    {
        try {
            $this->files->delete($user, $ncPath, self::PROBE);
            $output->writeln('<comment>probe file removed</comment>');
        } catch (NotFoundException) {
            // the write never happened
        } catch (\Throwable $e) {
            $output->writeln('<error>cleanup failed, delete ' . self::PROBE . ' manually: ' . $e->getMessage() . '</error>');
        }
    }
}
