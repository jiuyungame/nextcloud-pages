<?php

declare(strict_types=1);

namespace OCA\Pages\Command;

use OCA\Pages\Service\AuthorSourcesService;
use OCA\Pages\Service\BridgeClient;
use OCA\Pages\Service\BridgeException;
use OCA\Pages\Service\SiteRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ pages:push` — pushes the app's site configuration to pages-bridge; the command-line
 * equivalent of the "Push to bridge" button, for scripted deploys and troubleshooting.
 *
 * Exit codes are the retry taxonomy, not a boolean: `0` pushed, `1` failed, `2` the bridge did not
 * answer at all and the same command may simply be run again. A timer or a CI step can tell "the
 * service is down, retry" from "the service refused this configuration, a human has to look", which a
 * single failure code cannot express.
 */
class PushConfig extends Command
{
    /** The bridge answered "no" (or there is nothing to push): repeating the call cannot help. */
    public const EXIT_FAILED = 1;
    /** The bridge is unreachable: the call itself was fine, so a retry can (see isRetryable()). */
    public const EXIT_RETRYABLE = 2;

    public function __construct(
        private SiteRegistry $registry,
        private BridgeClient $bridge,
        private AuthorSourcesService $authorSources,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('pages:push')
            ->setDescription('Push the Pages site configuration to pages-bridge (exit 2 = bridge unreachable, retry later)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // single site: the emptiness check is "is there a site at all", not "is the list empty"
        if ($this->registry->getSite() === null) {
            $output->writeln('<error>No sites are configured yet.</error>');
            return self::EXIT_FAILED;
        }
        try {
            // the authors' folders travel with the site, exactly like the settings page's push: a
            // push without them would drop every author from the bridge (the pushed site replaces
            // the previous one), which is the opposite of what this command is for
            $result = $this->authorSources->pushWithAuthors($this->bridge);
        } catch (BridgeException $e) {
            // the same record a failing save writes, so `pages:doctor` can report a push that was
            // started from the shell as well as one started from the browser
            $this->registry->recordPushFailure([
                'author' => '',
                'code' => $e->getBridgeCode(),
                'message' => $e->getMessage(),
                'retryable' => $e->isRetryable(),
            ]);
            $output->writeln('<error>' . $e->getBridgeCode() . ': ' . $e->getMessage() . '</error>');
            foreach ($e->getDetails() as $key => $value) {
                $output->writeln('  ' . $key . ': ' . (is_scalar($value) ? (string)$value : json_encode($value, JSON_UNESCAPED_UNICODE)));
            }
            if ($e->isRetryable()) {
                $output->writeln(sprintf(
                    '<comment>Exit %d: the bridge is unreachable, so nothing was decided - run this command again once it is back (systemctl status pages-bridge).</comment>',
                    self::EXIT_RETRYABLE,
                ));

                return self::EXIT_RETRYABLE;
            }
            $output->writeln(sprintf(
                '<comment>Exit %d: the bridge answered and refused this configuration, so repeating the command cannot help; fix the reported cause first.</comment>',
                self::EXIT_FAILED,
            ));

            return self::EXIT_FAILED;
        }
        // an accepted push is the newest truth: the doctor's "last push failure" row goes away with it
        $this->registry->recordPushFailure(null);
        $output->writeln(sprintf(
            '<info>Pushed.</info> authors=%d config_source=%s revision=%s site=%s',
            (int)($result['authors'] ?? 0),
            (string)($result['result']['config_source'] ?? '?'),
            (string)($result['result']['revision'] ?? '?'),
            (string)($result['result']['site'] ?? '?'),
        ));
        foreach (($result['errors'] ?? []) as $uid => $message) {
            $output->writeln(sprintf('<comment>author %s could not be read: %s</comment>', (string)$uid, (string)$message));
        }
        return 0;
    }
}
