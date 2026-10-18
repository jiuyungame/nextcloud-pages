<?php

declare(strict_types=1);

namespace OCA\Pages\Command;

use OCA\Pages\Service\BridgeClient;
use OCA\Pages\Service\BridgeParams;
use OCA\Pages\Service\SiteRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ pages:config get|set|list` — the global bridge parameters, outside the settings page.
 *
 * The parameters themselves are the bridge's own: its TOML file is where they are documented and where
 * a hand-written value lives, and this command is the scriptable way to override one from Nextcloud
 * (the same set the app pushes, stored under `bridge_global`). The settings page deliberately does not
 * show them any more - it keeps the per-instance facts an administrator must decide, and "not on the
 * page" must never mean "invisible", which is what `list` and `pages:doctor --json` answer.
 *
 * The key whitelist is the bridge's own: {@see SiteRegistry::GLOBAL_TYPES}. An unknown key is refused
 * here, with the settable keys named, instead of travelling to the bridge to be rejected there.
 */
class BridgeConfig extends Command
{
    private const ACTIONS = ['get', 'set', 'list'];

    public function __construct(
        private SiteRegistry $registry,
        private BridgeClient $bridge,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('pages:config')
            ->setDescription('Read or set the global pages-bridge parameters (list | get <key> | set <key> <value>)')
            ->addArgument('action', InputArgument::REQUIRED, 'list, get or set')
            ->addArgument('key', InputArgument::OPTIONAL, 'parameter name (see "occ pages:config list")')
            ->addArgument('value', InputArgument::OPTIONAL, 'new value for set; an empty value removes the override');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = strtolower(trim((string)$input->getArgument('action')));
        if (!in_array($action, self::ACTIONS, true)) {
            $output->writeln(sprintf(
                '<error>Unknown action "%s". Use one of: %s.</error>',
                $action,
                implode(', ', self::ACTIONS),
            ));

            return 1;
        }

        $key = trim((string)$input->getArgument('key'));
        if ($action === 'list') {
            $this->renderList($output);

            return 0;
        }
        if ($key === '') {
            $output->writeln('<error>pages:config ' . $action . ' needs a key. Settable keys: ' . implode(', ', SiteRegistry::globalKeys()) . '</error>');

            return 1;
        }
        if (!array_key_exists($key, SiteRegistry::GLOBAL_TYPES)) {
            // the list is printed with the refusal: a typo should be fixable from the error alone
            $output->writeln(sprintf('<error>Unknown bridge parameter "%s".</error>', $key));
            $output->writeln('Settable keys: ' . implode(', ', SiteRegistry::globalKeys()));

            return 1;
        }

        if ($action === 'get') {
            return $this->renderOne($output, $key);
        }

        // an omitted value is not "remove it": removing an override is a decision, and it has to be
        // said explicitly (an empty string), not inferred from a missing argument
        if ($input->getArgument('value') === null) {
            $output->writeln(sprintf(
                '<error>pages:config set %s needs a value. Pass an empty string to remove the override: occ pages:config set %s \'\'</error>',
                $key,
                $key,
            ));
            $output->writeln('For example: ' . $this->example($key));

            return 1;
        }

        return $this->write($output, $key, (string)$input->getArgument('value'));
    }

    /**
     * Every settable key with its value, its type and where the value comes from. The origin column is
     * the same vocabulary the settings page used: `app` when Nextcloud pushes the value, `toml` or
     * `default` when the bridge answers with its own, `-` when it could not be asked.
     */
    private function renderList(OutputInterface $output): void
    {
        $stored = $this->registry->getGlobal();
        $sources = $this->bridgeSources();
        $table = new Table($output);
        $table->setHeaders(['parameter', 'type', 'value', 'from']);
        foreach (SiteRegistry::globalKeys() as $key) {
            $appSet = array_key_exists($key, $stored);
            // a value is always rendered as text: a list (exclude/unexclude) is a cell like any other
            $value = $appSet
                ? $this->format($stored[$key])
                : (array_key_exists($key, $sources['effective']) ? $this->format($sources['effective'][$key]) : '(not set)');
            $table->addRow([
                $key,
                SiteRegistry::GLOBAL_TYPES[$key],
                $value,
                $appSet ? 'app' : ($sources['sources'][$key] ?? '-'),
            ]);
        }
        $table->render();
        if ($sources['effective'] === []) {
            // no answer from the bridge: the values above are what this app has stored, and the origin
            // column cannot say more than "-". Said out loud, so a silent column is not read as "default".
            $output->writeln('<comment>The bridge could not be asked for its effective values, so the "from" column covers the stored configuration only.</comment>');
        }
        $output->writeln(sprintf(
            '<comment>%d parameter(s) set by this app. A value set here reaches the bridge with "occ pages:push".</comment>',
            count(array_intersect_key($stored, SiteRegistry::GLOBAL_TYPES)),
        ));
    }

    /** One key: the value on the first line, where it comes from on the second. */
    private function renderOne(OutputInterface $output, string $key): int
    {
        $stored = $this->registry->getGlobal();
        if (array_key_exists($key, $stored)) {
            $output->writeln($this->format($stored[$key]));
            $output->writeln(sprintf('<comment>from app (%s)</comment>', SiteRegistry::GLOBAL_TYPES[$key]));

            return 0;
        }
        $sources = $this->bridgeSources();
        if (array_key_exists($key, $sources['effective'])) {
            $output->writeln($this->format($sources['effective'][$key]));
            $output->writeln(sprintf('<comment>from %s (%s)</comment>', $sources['sources'][$key] ?? 'the bridge', SiteRegistry::GLOBAL_TYPES[$key]));

            return 0;
        }
        $output->writeln('(not set)');
        $output->writeln(sprintf(
            '<comment>the bridge keeps its own %s value for %s; set one with "occ pages:config set %s &lt;value&gt;"</comment>',
            SiteRegistry::GLOBAL_TYPES[$key],
            $key,
            $key,
        ));

        return 0;
    }

    /**
     * Stores (or removes) one override. The value is coerced to the type the bridge expects before it is
     * stored: a bool sent as the string "false" is truthy in Python, so an uncoerced value is a silent
     * switch-flip waiting to happen.
     */
    private function write(OutputInterface $output, string $key, string $raw): int
    {
        try {
            // BridgeParams::coerce() rejects a value the key's type cannot take, in the words of the
            // rule that failed - a typo has to be a refused write, not a stored surprise
            $value = BridgeParams::coerce($key, $raw);
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            $output->writeln(sprintf(
                '<comment>Type of %s: %s. For example: %s</comment>',
                $key,
                SiteRegistry::GLOBAL_TYPES[$key],
                $this->example($key),
            ));

            return 1;
        }
        $this->registry->mergeGlobal([$key => $value]);
        if ($value === null) {
            $output->writeln(sprintf('<info>Removed</info> %s: the bridge uses its own TOML or default value again.', $key));

            return 0;
        }
        $output->writeln(sprintf('<info>Set</info> %s = %s (%s)', $key, $this->format($value), SiteRegistry::GLOBAL_TYPES[$key]));
        $output->writeln('<comment>Stored in Nextcloud. Run "occ pages:push" to hand it to the bridge.</comment>');

        return 0;
    }

    /**
     * What the bridge really holds for each key, when it can be asked: `/env` reports the effective value
     * and the origin it applied. An unreachable bridge is not an error here - the stored set is then all
     * there is, and the origin column says so instead of inventing one.
     *
     * @return array{effective:array<string,mixed>,sources:array<string,string>}
     */
    private function bridgeSources(): array
    {
        try {
            $env = $this->bridge->environment();
        } catch (\Throwable) {
            return ['effective' => [], 'sources' => []];
        }
        $sources = [];
        foreach (is_array($env['sources'] ?? null) ? $env['sources'] : [] as $key => $origin) {
            if (is_string($origin) && $origin !== '') {
                $sources[(string)$key] = $origin;
            }
        }

        return [
            'effective' => is_array($env['effective'] ?? null) ? $env['effective'] : [],
            'sources' => $sources,
        ];
    }

    /** A value as one line of text: lists joined like the TOML writes them, booleans as true/false. */
    private function format(mixed $value): string
    {
        if (is_array($value)) {
            return '[' . implode(', ', array_map(static fn (mixed $item): string => is_scalar($item) ? (string)$item : '', $value)) . ']';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string)$value;
    }

    /** One valid value for the key, so the error message shows the shape instead of only the type name. */
    private function example(string $key): string
    {
        return match (SiteRegistry::GLOBAL_TYPES[$key] ?? 'string') {
            'int' => 'occ pages:config set ' . $key . ' 120',
            'float' => 'occ pages:config set ' . $key . ' 0.2',
            'bool' => 'occ pages:config set ' . $key . ' true',
            'stringList' => 'occ pages:config set ' . $key . ' ".vscode, .idea"',
            default => 'occ pages:config set ' . $key . ' /srv/pages',
        };
    }
}
