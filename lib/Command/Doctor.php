<?php

declare(strict_types=1);

namespace OCA\Pages\Command;

use OCA\Pages\AppInfo\Application;
use OCA\Pages\Db\Article;
use OCA\Pages\Service\ArticleReviewService;
use OCA\Pages\Service\AuthorSourcesService;
use OCA\Pages\Service\BridgeClient;
use OCA\Pages\Service\BridgeException;
use OCA\Pages\Service\FileService;
use OCA\Pages\Service\PageUrlService;
use OCA\Pages\Service\ReviewStore;
use OCA\Pages\Service\SiteRegistry;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Server;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ pages:doctor` — full-chain check of app config, bridge connectivity and protocol version,
 * the site folder and the build toolchain, with an actionable hint for every failing check.
 */
class Doctor extends Command
{
    private AuthorSourcesService $authorSources;
    private ArticleReviewService $review;
    private ReviewStore $store;
    private PageUrlService $pageUrls;
    private IL10N $l10n;

    public function __construct(
        private SiteRegistry $registry,
        private BridgeClient $bridge,
        private FileService $files,
        private IConfig $config,
        private IURLGenerator $urlGenerator,
        private IAppManager $appManager,
        private IGroupManager $groupManager,
        private IUserManager $userManager,
        // resolved lazily: this command is built from info.xml, where the container may or may not
        // autowire the newer services, and a doctor that cannot start is worse than one that asks
        ?AuthorSourcesService $authorSources = null,
        ?ArticleReviewService $review = null,
        ?ReviewStore $store = null,
        ?IFactory $l10nFactory = null,
        ?PageUrlService $pageUrls = null,
    ) {
        $this->authorSources = $authorSources ?? Server::get(AuthorSourcesService::class);
        $this->review = $review ?? Server::get(ArticleReviewService::class);
        $this->store = $store ?? Server::get(ReviewStore::class);
        $this->pageUrls = $pageUrls ?? Server::get(PageUrlService::class);
        // the factory rather than `Server::get(IL10N::class)`: that interface is registered in an app's
        // own container only, while the factory is a service of the server container and works either way
        $this->l10n = ($l10nFactory ?? Server::get(IFactory::class))->get(Application::APP_ID);
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('pages:doctor')
            ->setDescription('Check the full Pages / pages-bridge chain')
            ->addOption('json', null, \Symfony\Component\Console\Input\InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $asJson = (bool)$input->getOption('json');
        $report = ['ok' => true, 'checks' => []];

        $add = function (string $name, bool $ok, string $detail, ?string $hint = null, bool $warning = false) use (&$report): void {
            $report['checks'][] = ['name' => $name, 'ok' => $ok, 'detail' => $detail, 'hint' => $hint, 'warning' => $warning];
            if (!$ok && !$warning) {
                $report['ok'] = false;
            }
        };

        // app-side configuration
        $url = $this->registry->getBridgeUrl();
        $add('bridge URL', $url !== '', $url !== '' ? $url : '(not configured)');
        $add(
            'shared secret',
            $this->registry->getSharedSecret() !== '',
            $this->registry->secretFingerprint(SiteRegistry::KEY_SHARED_SECRET) ?: '(not configured)',
            'Generate it in the bridge TOML, then paste the same value into the admin settings here.',
        );

        // the two grants and the group lists that grant them; administrators always hold both, so an
        // empty list is "administrators only" and never "nobody". Publishing is part of reviewing
        // since the merge (审核与发布), so it is reported in the same row instead of a row of its own.
        $authorGroups = $this->registry->getAuthorGroups();
        $reviewGroups = $this->registry->getReviewGroups();
        $missingGroups = [];
        foreach (array_unique(array_merge($authorGroups, $reviewGroups)) as $gid) {
            if (!$this->groupManager->groupExists($gid)) {
                $missingGroups[] = $gid;
            }
        }
        $grantDetail = [
            'author: ' . ($authorGroups === [] ? 'every signed-in user' : implode(', ', $authorGroups)),
            'review & publish: ' . ($reviewGroups === [] ? 'administrators only' : implode(', ', $reviewGroups)),
            'administrators always hold both',
        ];
        $add(
            'author / review (incl. publishing) groups',
            $missingGroups === [],
            implode(' · ', $grantDetail),
            $missingGroups === []
                ? null
                : 'These groups no longer exist: ' . implode(', ', $missingGroups)
                    . '. Fix the grant in the admin settings (Pages → Permissions) so that no dead group stays in the config.',
        );

        // connectivity and protocol version
        $health = null;
        try {
            $health = $this->bridge->health();
            $add('bridge reachable', true, sprintf(
                '%s · %s · protocol v%s',
                (string)($health['service'] ?? '?'),
                (string)($health['version'] ?? '?'),
                (string)($health['protocol'] ?? '?'),
            ));
        } catch (BridgeException $e) {
            /*
             * Two different problems, two different next steps - that is the whole point of the retry
             * axis. "Unreachable" means no answer at all: the service is down (or proxied away), the
             * request decided nothing, and repeating it after a restart is the fix. Every other code
             * means the service answered and *refused*: it will keep refusing until the cause it names
             * is fixed, so "restart it" would send the administrator in the wrong direction.
             */
            $down = $e->isRetryable();
            $add(
                'bridge reachable',
                false,
                sprintf(
                    '%s: %s: %s',
                    $down ? 'unreachable (the service did not answer)' : 'the service answered and rejected the request',
                    $e->getBridgeCode(),
                    $e->getMessage(),
                ),
                match (true) {
                    $down => 'Start the service: "systemctl status pages-bridge" on the host (if the host sets '
                        . 'http_proxy, no_proxy must include 127.0.0.1). Nothing was changed by the failed call, so '
                        . 'the same request can simply be repeated - "occ pages:push" exits 2 while it is down.',
                    $e->getBridgeCode() === 'PROTOCOL_MISMATCH' => 'Bridge and app versions do not match: re-run bridge/install.sh from the app package.',
                    $e->getBridgeCode() === 'NOT_CONFIGURED' => 'Fill in the bridge URL and pair this app first (admin settings → Pages).',
                    $e->getBridgeCode() === 'LOCAL_ACCESS_BLOCKED' => 'Keep the Pages loopback channel enabled (app setting bridge_loopback_bypass), or set allow_local_remote_servers in config.php.',
                    default => 'The service refused this request (' . $e->getBridgeCode() . '), so a retry repeats the same '
                        . 'refusal: fix the cause it names, then run "occ pages:push". Its journal has the detail: '
                        . '"journalctl -u pages-bridge -n 50".',
                },
            );
        }

        // the site folder; this also exercises the Files API read path
        $site = $this->registry->getSite();
        // initialised here: the author-space check below reads it even when no site is configured yet
        $ncUser = '';
        $add(
            'site configured',
            $site !== null,
            $site !== null ? 'yes' : '(not configured)',
            $site === null
                ? 'Admin settings → Pages → Site: fill in the site storage user, the Nextcloud folder and the git remote.'
                : null,
        );
        if ($site !== null) {
            $ncPath = (string)($site['nc_path'] ?? '');
            $ncUser = (string)($site['nc_user'] ?? '');
            // no hardcoded username fallback: that would disguise a missing field as a missing folder
            if ($ncUser === '') {
                $add(
                    'site storage user',
                    false,
                    '(not configured)',
                    'Admin settings → Pages → Site → Site storage user; it maps to the <user> level of the data directory.',
                );
            } elseif (!$this->userManager->userExists($ncUser)) {
                // no files folder can exist without the account, so say which setting is wrong: a
                // "source folder missing" here would send the administrator looking for the folder
                $add(
                    'site storage user',
                    false,
                    $ncUser . ' (no such user)',
                    'Admin settings → Pages → Site → Site storage user: "' . $ncUser . '" does not exist on this instance, '
                        . 'so its files folder cannot be read (and the bridge resolves the source under that name).',
                );
            } else {
                $exists = $ncPath !== '' && $this->files->siteExists($ncUser, $ncPath);
                $add(
                    'source folder',
                    $exists,
                    $ncPath . ($exists ? ' ✓' : ' (missing)'),
                    $exists ? null : 'Run "occ pages:push" and then POST .../apps/pages/api/site/init to let the bridge back-fill it.',
                );
                if ($exists) {
                    // really list the tree: this also exercises user-folder resolution, permissions and the Files API
                    try {
                        $tree = $this->files->tree($ncUser, $ncPath);
                        $files = 0;
                        $dirs = 0;
                        foreach ($tree['nodes'] as $node) {
                            $node['type'] === 'dir' ? $dirs++ : $files++;
                        }
                        $add(
                            'site readable (Files API)',
                            true,
                            sprintf('%d folders / %d files%s', $dirs, $files, $tree['truncated'] ? ' (truncated)' : ''),
                        );
                    } catch (\Throwable $e) {
                        $add('site readable (Files API)', false, $e->getMessage(), 'Check permissions and ownership of that folder.');
                    }
                }
            }
        }

        // the author space: where each participating user keeps the files the app publishes for them
        $this->checkAuthorSpace($add, $ncUser);
        // and the site's own view of that space: a mirror the site does not read is invisible
        $this->checkSiteAuthorSource($add, $site);

        // the bridge's view of the site: protocol 3 carries no site ids, so "in sync" means the
        // config revision the bridge loaded is the revision this app pushed last
        if ($health !== null && $site !== null) {
            $pushed = $this->registry->getPushedRevision();
            $onBridge = (int)($health['config_revision'] ?? 0);
            $synced = $pushed > 0 && $onBridge === $pushed;
            $add(
                'config synced to the bridge',
                $synced,
                $synced
                    ? sprintf('revision %d', $pushed)
                    : sprintf('the bridge loaded revision %d, the app pushed %d', $onBridge, $pushed),
                $synced ? null : 'Use "Push to bridge" in the admin settings, or call POST /apps/pages/api/bridge/push (occ pages:push).',
            );
        }

        /*
         * The global bridge parameters. They are no longer on the settings page - the page holds only
         * the per-instance facts an administrator has to decide, while these are the bridge's own
         * tuning and live in the bridge TOML or are set with `occ pages:config`. This report is what
         * keeps them visible: every settable key with the value that is really in force and where it
         * comes from, in the plain table as one summary line and key by key in `--json`.
         */
        $report['config'] = $this->bridgeParameters($health);
        $fromApp = 0;
        $stale = [];
        foreach ($report['config'] as $key => $entry) {
            if ($entry['source'] === 'app') {
                $fromApp++;
            }
            if ($entry['source'] === 'app' && array_key_exists('effective', $entry) && !$this->sameParameter($entry['value'], $entry['effective'])) {
                $stale[] = $key;
            }
        }
        $add(
            'bridge parameters',
            $stale === [],
            sprintf(
                '%d settable · %d set by this app · %d from the bridge TOML or its defaults%s',
                count($report['config']),
                $fromApp,
                count($report['config']) - $fromApp,
                $stale === [] ? '' : ' · pushed but not in force: ' . implode(', ', $stale),
            ),
            $stale === []
                ? null
                : 'The bridge still runs other values for these keys: run "occ pages:push", or read them back with "occ pages:config get <key>".',
        );

        /*
         * The last push the bridge never accepted. The mark on the entry itself (the "not pushed ·
         * retry" badge) lives in the browser, so this record is the durable answer: who saved, when,
         * with which code, and whether trying again can help at all. It is a warning and not a failure:
         * a failure that has since been retried successfully must not keep the whole check red.
         */
        $failure = $this->registry->getLastPushFailure();
        if ($failure === null) {
            $add('last push failure', true, 'none since this record exists (every push reached the bridge)');
        } else {
            $add(
                'last push failure',
                false,
                sprintf(
                    '%s · author %s · %s · retryable: %s · %s',
                    $failure['ts'] > 0 ? date('Y-m-d H:i:s', $failure['ts']) : '(time unknown)',
                    $failure['author'] === '' ? '(occ pages:push)' : $failure['author'],
                    $failure['code'],
                    $failure['retryable'] ? 'yes' : 'no',
                    $failure['message'] === '' ? '(no message)' : $failure['message'],
                ),
                $failure['retryable']
                    ? 'The bridge did not answer, so nothing was decided: start it ("systemctl status pages-bridge"), then retry '
                        . 'from the entry\'s "not pushed · retry" badge or run "occ pages:push" (it exits 2 while the service is down).'
                    : 'The bridge answered and refused this push, so repeating it repeats the refusal: fix the cause it names, then '
                        . 'run "occ pages:push". The author\'s file is saved; only the mirror did not take it.',
                true,
            );
        }

        // app navigation entry
        //
        // The entry is no longer declared in info.xml: Application::boot() registers it at runtime so
        // that it is skipped for anonymous requests (the preview is open to every signed-in user, so
        // there is no group to restrict the entry to). What can be asserted here is the contract that
        // registration depends on: the route must resolve, and the icon must be a real file that
        // imagePath() can turn into a URL. A runtime entry is stored exactly as handed over, so a
        // route or a bare icon name that only info.xml would expand leaves a menu tile without a
        // link and without an icon - tests/verify-admin.sh asserts the resolved entry over HTTP.
        $navRoute = Application::NAV_ROUTE;
        $navUrl = '';
        $navError = '';
        try {
            // Router::generate() logs and returns an empty string instead of throwing, so both the
            // exception and the empty result have to be treated as a failure
            $navUrl = $this->urlGenerator->linkToRoute($navRoute);
            if (!is_string($navUrl) || $navUrl === '') {
                $navError = 'generated an empty URL';
                $navUrl = '';
            }
        } catch (\Throwable $e) {
            $navError = $e->getMessage();
        }

        $navIcon = Application::NAV_ICON;
        $navIconUrl = '';
        try {
            $navIconUrl = (string)$this->urlGenerator->imagePath(Application::APP_ID, $navIcon);
        } catch (\Throwable $e) {
            $navError = $navError !== '' ? $navError : $e->getMessage();
        }
        $navIconFile = \dirname(__DIR__, 2) . '/img/' . $navIcon;

        $navEntry = [
            'name' => Application::NAV_NAME,
            'route' => Application::NAV_ROUTE,
            'icon' => Application::NAV_ICON,
            'order' => Application::NAV_ORDER,
        ];

        // the route is handed to linkToRoute() as-is, so it has to be the full <appid>.<controller>.<action>
        $navOk = $navUrl !== '' && str_starts_with($navRoute, Application::APP_ID . '.');
        if (!$navOk && $navError === '' && $navUrl !== '') {
            $navError = 'route lacks the app prefix, expected ' . Application::APP_ID . '.' . $navRoute;
        }
        $navOk = $navOk && $navIconUrl !== '' && is_file($navIconFile);
        $add(
            'app navigation entry (registered at runtime)',
            $navOk,
            sprintf(
                '%s (route=%s, icon=%s, order=%s) → %s',
                $navEntry['name'],
                $navRoute,
                $navIconUrl !== '' ? $navIconUrl : $navEntry['icon'],
                (string)$navEntry['order'],
                $navUrl !== ''
                    ? $navUrl
                    : '(unresolvable' . ($navError !== '' ? ': ' . $navError : '') . ')',
            ),
            $navOk
                ? null
                : 'Application::boot() must register the entry through INavigationManager::add() with a '
                    . 'resolved href (linkToRoute) and a resolved icon (imagePath); the icon file img/'
                    . $navIcon . ' has to exist.',
        );

        // routes must resolve: an unresolvable route answers the request with a 404 HTML page, not JSON
        $routesFile = \dirname(__DIR__, 2) . '/appinfo/routes.php';
        $declaredRoutes = is_file($routesFile) ? (require $routesFile) : [];
        $routeNames = [];
        $unresolvable = [];
        $resolved = [];
        foreach (($declaredRoutes['routes'] ?? []) as $route) {
            $name = (string)($route['name'] ?? '');
            $url = (string)($route['url'] ?? '');
            if ($name === '' || !str_contains($name, '#') || $url === '') {
                continue;
            }
            [$controller, $action] = explode('#', $name, 2);
            $routeName = Application::APP_ID . '.' . $controller . '.' . $action;
            $params = [];
            if (preg_match_all('/\{(\w+)\}/', $url, $matches) > 0) {
                foreach ($matches[1] as $param) {
                    $params[$param] = 'x';
                }
            }
            $routeNames[] = $routeName;
            try {
                // Router::generate() never throws: it logs and returns an empty string, so the return
                // value has to be checked as well (catching exceptions alone misses that case)
                $generated = $this->urlGenerator->linkToRoute($routeName, $params);
                if (!is_string($generated) || $generated === '') {
                    $unresolvable[] = $routeName . ' (generated empty)';
                } else {
                    $resolved[$routeName] = $generated;
                }
            } catch (\Throwable $e) {
                $unresolvable[] = $routeName . ' (' . $e->getMessage() . ')';
            }
        }
        $add(
            'routes resolvable',
            $routeNames !== [] && $unresolvable === [],
            $unresolvable === []
                ? sprintf('all %d routes resolve', count($routeNames))
                : 'unresolvable: ' . implode('; ', $unresolvable),
            $unresolvable === []
                ? null
                : 'Declared in routes.php but not known to the router: requests get a 404 (HTML, not JSON).',
        );
        foreach (['pages.preview.session', 'pages.preview.serve'] as $previewRoute) {
            $add(
                "preview route $previewRoute",
                isset($resolved[$previewRoute]),
                $resolved[$previewRoute] ?? '(unresolvable)',
            );
        }

        // route *matching* cannot be verified here (no public API exposes the router's collection);
        // scripts/deploy/deploy-app.sh probes every route with a real request instead.

        // page routes must skip CSRF: a bare GET carries no requesttoken and is rejected after login
        $pageRoutes = [
            'page route page#index' => [\OCA\Pages\Controller\PageController::class, 'index'],
            'preview route preview#serve' => [\OCA\Pages\Controller\PreviewController::class, 'serve'],
        ];
        foreach ($pageRoutes as $label => [$class, $method]) {
            $reflection = new \ReflectionMethod($class, $method);
            $hasAttribute = $reflection->getAttributes(\OCP\AppFramework\Http\Attribute\NoCSRFRequired::class) !== [];
            $hasAnnotation = str_contains((string)$reflection->getDocComment(), '@NoCSRFRequired');
            $add(
                $label,
                $hasAttribute || $hasAnnotation,
                $hasAttribute ? 'NoCSRFRequired attribute ✓' : ($hasAnnotation ? 'NoCSRFRequired annotation ✓' : 'missing'),
                $hasAttribute || $hasAnnotation
                    ? null
                    : 'Opening the page from the address bar after login reports "CSRF check failed". Add #[NoCSRFRequired] (the route changes no state).',
            );
        }

        // admin settings page
        //
        // /settings/admin/pages is rendered by the settings framework rather than by an app route, so
        // route probing never covers it: it can 500 while every app route answers 401 as expected.
        $settingsClasses = [
            'admin settings panel' => \OCA\Pages\Settings\Admin::class,
            'admin settings section' => \OCA\Pages\Settings\AdminSection::class,
        ];
        foreach ($settingsClasses as $label => $class) {
            $exists = class_exists($class);
            // keep this concatenated: a full-width bracket right after a variable name parses as part of it
            $add(
                $label . ' (' . $class . ')',
                $exists,
                $exists ? 'class exists' : 'class not found',
                $exists ? null : '<settings> in info.xml declares this class; a typo in the class name breaks the settings page.',
            );
        }

        // legacy static/singleton APIs are removed in current Nextcloud and only fail at runtime,
        // often before the page renders; only APIs confirmed as removed are listed below
        $legacyPatterns = [
            '/\\\\OC::\$server->get[A-Za-z]+\(/' => '\OC::$server->get*()',
            '/\bOC_App::getAppPath\(/' => 'OC_App::getAppPath()',
            '/\bOC_App::getAppWebPath\(/' => 'OC_App::getAppWebPath()',
        ];
        $legacyCalls = [];
        $libDir = $this->appManager->getAppPath(Application::APP_ID) . '/lib';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($libDir));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            // comments and strings must be stripped first: a bad pattern quoted in a docblock is not a usage
            $source = $this->stripCommentsAndStrings((string)file_get_contents($file->getPathname()));
            foreach ($legacyPatterns as $pattern => $label) {
                if (preg_match($pattern, $source) === 1) {
                    $legacyCalls[] = basename($file->getPathname()) . ' → ' . $label;
                }
            }
        }
        $add(
            'no removed legacy APIs (\OC::$server->get* / OC_App::getAppPath)',
            $legacyCalls === [],
            $legacyCalls === [] ? 'static scan clean' : implode('; ', array_slice($legacyCalls, 0, 3)),
            $legacyCalls === []
                ? null
                : 'Use constructor injection, or \OCP\Server::get(Xxx::class) / IAppManager. '
                    . 'Such calls throw "Call to undefined method" and often break the whole page.',
        );

        // the app itself never touches the host
        $add(
            'app privilege boundary',
            true,
            'Pages installs and starts no host processes; systemd owns the bridge lifecycle.',
        );

        if ($asJson) {
            $output->writeln(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}');
        } else {
            $table = new Table($output);
            $table->setHeaders(['check', 'result', 'detail']);
            foreach ($report['checks'] as $check) {
                // a warning is a check that is not satisfied but must not fail the command (see `$add`):
                // printing it as FAIL next to "all checks passed" would contradict both
                $result = match (true) {
                    $check['ok'] => '<info>OK</info>',
                    (bool)$check['warning'] => '<comment>WARN</comment>',
                    default => '<error>FAIL</error>',
                };
                $table->addRow([$check['name'], $result, $check['detail']]);
            }
            $table->render();
            foreach ($report['checks'] as $check) {
                // the hint belongs to a check that is not satisfied - a warning included, otherwise the
                // one line that says what to do next would be the only thing not printed
                if ($check['hint'] === null || ($check['ok'] && !$check['warning'])) {
                    continue;
                }
                $output->writeln('<comment>→ ' . $check['name'] . ': </comment>' . $check['hint']);
            }
            $output->writeln($report['ok'] ? '<info>All checks passed.</info>' : '<error>Some checks failed, see the hints above.</error>');
        }

        return $report['ok'] ? 0 : 1;
    }

    /**
     * The site's own template configuration: which directory the site's author mirror reads.
     *
     * The app mirrors every author tree into `/<author_root>/<uid>/…` and the site's `_plugins/Authors.rb`
     * mirrors every sub-folder of `authors.source` into the site. The two names are the same setting on
     * two sides, and when they differ the files reach the site's source folder but no page is ever
     * rendered from them - the failure is silent, which is exactly what this check is for.
     *
     * A site without a readable `_config.yml` is a warning rather than a failure: the plugin's default
     * (`authors-src`) applies then, and only the site owner can say whether that is intended.
     *
     * @param \Closure(string,bool,string,?string=,bool=):void $add
     * @param array<string,mixed>|null $site
     */
    private function checkSiteAuthorSource(\Closure $add, ?array $site): void
    {
        $root = $this->registry->getAuthorRoot();
        $ncUser = $site === null ? '' : trim((string)($site['nc_user'] ?? ''));
        $ncPath = $site === null ? '' : trim((string)($site['nc_path'] ?? ''));
        if ($ncUser === '' || $ncPath === '') {
            $add(
                'site author source',
                false,
                '(no readable site)',
                'Configure the site storage user and folder first (Admin settings → Pages → Site); the check compares the site\'s authors.source with the author content directory.',
            );

            return;
        }
        $block = $this->pageUrls->authorsConfig($ncUser, $ncPath);
        if ($block === null) {
            $add(
                'site author source',
                true,
                sprintf('no %s in %s (the template default %s applies)', PageUrlService::CONFIG_FILE, $ncPath, PageUrlService::DEFAULT_SOURCE),
                $this->l10n->t(
                    'The site has no readable %1$s, so the author mirror falls back to its default directory %2$s. If the site is not the Jekyll authors template at all, ignore this; otherwise the app\'s author content directory (%3$s) has to be the same name.',
                    [PageUrlService::CONFIG_FILE, PageUrlService::DEFAULT_SOURCE, $root],
                ),
                true,
            );

            return;
        }
        $declared = trim((string)($block['source'] ?? ''));
        $declared = $declared !== '' ? $declared : PageUrlService::DEFAULT_SOURCE;
        $ok = $declared === $root;
        $add(
            'site author source',
            $ok,
            sprintf('authors.source: %s · app author content directory: %s', $declared, $root),
            $ok
                ? null
                : $this->l10n->t(
                    'The site reads its author folders from %1$s, the app writes them to %2$s: the mirror is invisible to the site, so no author page is ever built. Set authors.source to %2$s in the site\'s %3$s (or change the author content directory to %1$s), then rebuild.',
                    [$declared, $root, PageUrlService::CONFIG_FILE],
                ),
        );
    }

    /**
     * The author space: the site directory every author's tree is mirrored into, the state of every
     * participating user's folder, and the one naming rule that costs pages without failing anything.
     *
     * A missing author area is a **warning**, not a failure: the folder is created on first use, and a
     * user who has not written anything is a normal state. The review manifest is treated the same way:
     * it appears with the first review action, so a site whose files were never submitted is not broken.
     * What fails is an area the Files API cannot read, which leaves files that will never be published
     * while every other check passes.
     *
     * A `_`-prefixed directory inside an author area is mirrored but Jekyll does not render pages inside
     * it (Jekyll's own convention), so it is reported as a warning: the files are on the site, they just
     * never become pages, and only the author can decide whether that was intended.
     *
     * @param \Closure(string,bool,string,?string=,bool=):void $add
     */
    private function checkAuthorSpace(\Closure $add, string $ncUser): void
    {
        $root = $this->registry->getAuthorRoot();
        $add(
            'author content directory',
            true,
            '/' . $root . '/<user>/… in the site (area: ' . SiteRegistry::AUTHOR_AREA . '/ in every author\'s files)',
            null,
        );

        $groups = $this->registry->getAuthorGroups();
        $deadGroups = [];
        foreach ($groups as $gid) {
            if (!$this->groupManager->groupExists($gid)) {
                $deadGroups[] = $gid;
            }
        }
        $add(
            'authoring groups',
            $deadGroups === [],
            $groups === [] ? 'every signed-in user may author' : implode(', ', $groups),
            $deadGroups === []
                ? null
                : 'These groups no longer exist: ' . implode(', ', $deadGroups)
                    . '. Fix the grant in the admin settings (Pages → Permissions).',
        );

        $authors = $this->authorSources->authors();
        $add(
            'authors',
            $authors !== [],
            $authors === []
                ? '(nobody may author)'
                : sprintf('%d author(s): %s', count($authors), implode(', ', array_slice($authors, 0, 8)) . (count($authors) > 8 ? ', …' : '')),
            $authors === [] && $groups !== []
                ? 'None of the authoring groups has a member; add one, or clear the grant so every signed-in user may author.'
                : null,
        );

        $unreadable = [];
        $missing = [];
        $posts = 0;
        $files = 0;
        $underscored = [];
        foreach ($authors as $uid) {
            $counts = $this->authorSources->counts($uid);
            if (!$counts['exists']) {
                $missing[] = $uid;
            } elseif (!$counts['readable']) {
                $unreadable[] = $uid;
            }
            $posts += $counts['posts'];
            $files += $counts['files'];
            foreach ($this->authorSources->underscoredDirectories($uid) as $dir) {
                $underscored[] = $uid . ': ' . $dir;
            }
        }
        $add(
            'author areas',
            $unreadable === [],
            sprintf(
                '%s/ · %d author(s) · %d file(s), %d renderable%s',
                SiteRegistry::AUTHOR_AREA,
                count($authors),
                $files,
                $posts,
                $missing === [] ? '' : ' · not created yet: ' . implode(', ', $missing),
            ),
            $unreadable === []
                ? ($missing === [] ? null : 'The area of these users does not exist yet; it is created the first time they save a file.')
                : 'Cannot read the author area of: ' . implode(', ', $unreadable) . '. Check that the account exists and its files are readable.',
            $unreadable === [],
        );

        $add(
            'author folder names',
            true,
            $underscored === []
                ? 'no "_"-prefixed directories'
                : implode(', ', array_slice($underscored, 0, 8)) . (count($underscored) > 8 ? ', …' : ''),
            $underscored === []
                ? null
                : 'A "_"-prefixed directory inside an author area is mirrored, but Jekyll does not render pages inside it. '
                    . 'Rename the folder (or accept it as static files) if those files should become pages.',
            true,
        );

        // the visibility workflow: how much sits in each state, and whether anything has been waiting
        // for a decision too long. Two sets of numbers, because the manifest is the truth and the table
        // is its cache: they drifting apart is exactly what this check is here to show.
        try {
            $manifest = $this->store->read();
            $counts = $this->review->counts();
            $stale = $this->review->staleRequests();
            $location = $this->store->location();
            // A manifest that is not there while the site has author posts is a warning and not a
            // failure: it is created by the first visibility action (the same way an author area appears
            // with the first saved post), and until then every article reads as internal - which is the
            // honest answer for a site whose bookkeeping was never written.
            $noManifest = $posts > 0 && !$this->store->exists();
            $detail = $this->l10n->t(
                'internal %d · requested %d · external %d · published files %d · manifest: %d article(s), %d published file(s) · %s',
                [
                    $counts[Article::STATE_INTERNAL] ?? 0,
                    $counts[Article::STATE_REQUESTED] ?? 0,
                    $counts[Article::STATE_EXTERNAL] ?? 0,
                    $this->review->publishedCount(),
                    count($manifest['entries']),
                    count($manifest['published']),
                    $location,
                ],
            );
            if ($this->store->legacyExists()) {
                // a copy of the decisions is still in somebody's file tree: it decides nothing any more
                // (appdata wins), but it is a writable record that should not stay there
                $detail .= ' ' . $this->l10n->t(
                    '(%s holds an old copy of the manifest; it is not read any more and can be deleted)',
                    [$ncUser . '/' . ReviewStore::LEGACY_DIR . '/' . ReviewStore::FILE],
                );
            }
            $add(
                'visibility states',
                !$noManifest,
                $detail,
                $noManifest
                    ? $this->l10n->t(
                        'The visibility manifest %s is not there yet; it is written by the first visibility action (a request, a decision or a publish). It lives in the app\'s own private storage, not in anybody\'s files and not editable through the Files app, and until it exists every article counts as internal and nothing is on the site.',
                        [$location],
                    )
                    : ($stale === []
                        ? null
                        : sprintf(
                            '%d request(s) have been waiting longer than %d days: %s',
                            count($stale),
                            ArticleReviewService::STALE_REQUEST_DAYS,
                            implode(', ', array_slice($stale, 0, 5)),
                        )),
                $noManifest,
            );
        } catch (\Throwable $e) {
            $add(
                'visibility states',
                false,
                $e->getMessage(),
                'The article table may be missing or outdated: run "occ app:update pages" (or occ upgrade).',
            );
        }
    }

    /**
     * Every settable global bridge parameter with the value in force and where it comes from.
     *
     * Two sources are merged, and the bridge's own answer wins when it is there: `/env` reports the
     * effective value of each key together with the origin it applied (`app` when the configuration
     * this app pushed set it, otherwise the TOML file or the built-in default). Without a reachable
     * bridge the app's stored set is the only truth available, and a key it does not hold is reported
     * as `unknown` rather than as a default the app cannot know.
     *
     * `value` is what the app has for the key (null when it holds none), `effective` is what the bridge
     * reported when it answered - the two differ exactly when a push has not arrived yet.
     *
     * @param array<string,mixed>|null $health the reachable bridge's /health answer, or null
     * @return array<string,array{type:string,value:mixed,effective?:mixed,source:string}>
     */
    private function bridgeParameters(?array $health): array
    {
        $stored = $this->registry->getGlobal();
        $environment = null;
        if ($health !== null) {
            try {
                $environment = $this->bridge->environment();
            } catch (\Throwable) {
                // a bridge that answers /health but not /env is not a reason to fail the doctor: the
                // stored values are then reported on their own, and the origin column says "unknown"
                $environment = null;
            }
        }
        $effective = is_array($environment['effective'] ?? null) ? $environment['effective'] : [];
        $sources = is_array($environment['sources'] ?? null) ? $environment['sources'] : [];
        $out = [];
        foreach (SiteRegistry::globalKeys() as $key) {
            $appSet = array_key_exists($key, $stored);
            $entry = [
                'type' => SiteRegistry::GLOBAL_TYPES[$key],
                'value' => $appSet ? $stored[$key] : null,
                'source' => $appSet
                    ? 'app'
                    : (is_string($sources[$key] ?? null) && $sources[$key] !== '' ? $sources[$key] : 'unknown'),
            ];
            if (array_key_exists($key, $effective)) {
                $entry['effective'] = $effective[$key];
            }
            $out[$key] = $entry;
        }

        return $out;
    }

    /**
     * Whether a stored parameter and the value the bridge reports describe the same setting. Lists are
     * compared as text (the TOML writes `["a", "b"]`, the app stores `["a","b"]`) and everything else by
     * its string form, which is what makes `0.2` and `"0.2"` the same value and `false` and `"false"`
     * different ones.
     */
    private function sameParameter(mixed $stored, mixed $effective): bool
    {
        $flat = static function (mixed $value): string {
            if (is_array($value)) {
                return implode(', ', array_map(static fn (mixed $item): string => is_scalar($item) ? trim((string)$item) : '', $value));
            }
            if (is_bool($value)) {
                return $value ? 'true' : 'false';
            }

            return $value === null ? '' : trim((string)$value);
        };

        return $flat($stored) === $flat($effective);
    }

    /** Blanks out comments and string literals, so that a bad pattern quoted in a docblock is not scanned. */
    private function stripCommentsAndStrings(string $source): string
    {
        $out = '';
        foreach (\token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING], true)) {
                    $out .= ' ';
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }
        return $out;
    }
}
