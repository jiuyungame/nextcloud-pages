<?php

declare(strict_types=1);

namespace OCA\Pages\Command;

use OCA\Pages\Service\BridgeClient;
use OCA\Pages\Service\BridgeException;
use OCA\Pages\Service\DeltaQueue;
use OCA\Pages\Service\SiteRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ pages:delta-flush` — replays the author deltas the bridge did not take.
 *
 * The app queues a failed `add`/`update`/`remove` in appconfig ({@see DeltaQueue}) and replays it at
 * the next boot, bounded so a boot is never held open. This command is the explicit version for an
 * operator: it drains the queue now and prints what happened, with the same retry taxonomy as
 * `pages:push` - `0` everything was delivered, `1` entries remain (the bridge answered and refused,
 * or the round hit its limit), `2` the bridge is unreachable and the same command can be run again.
 */
class DeltaFlush extends Command
{
    public function __construct(
        private DeltaQueue $queue,
        private BridgeClient $bridge,
        private SiteRegistry $registry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('pages:delta-flush')
            ->setDescription('Replay the queued author deltas pages-bridge did not take (exit 2 = bridge unreachable)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum entries to replay in this run', (string)DeltaQueue::MAX_ENTRIES)
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Seconds one delta may take', (string)DeltaQueue::REPLAY_TIMEOUT)
            ->addOption('budget', null, InputOption::VALUE_REQUIRED, 'Seconds the whole run may take', '600');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pending = $this->queue->count();
        if ($pending === 0) {
            $output->writeln('<info>No queued author deltas.</info>');

            return 0;
        }
        if (!$this->registry->isConnected() || $this->registry->getSite() === null) {
            $output->writeln(sprintf(
                '<error>%d queued delta(s) cannot be replayed: no site or no pairing is configured.</error>',
                $pending,
            ));

            return PushConfig::EXIT_FAILED;
        }

        $limit = max(1, (int)$input->getOption('limit'));
        $timeout = max(1, (int)$input->getOption('timeout'));
        $budget = max(1, (int)$input->getOption('budget'));
        $output->writeln(sprintf(
            '<info>%d queued delta(s)</info> replaying up to %d (%d s each, %d s in total)',
            $pending,
            $limit,
            $timeout,
            $budget,
        ));

        $unreachable = false;
        $result = $this->queue->replay(
            function (array $body, int $perEntryTimeout) use ($output, &$unreachable): void {
                try {
                    $this->bridge->authorDelta($body, $perEntryTimeout);
                } catch (BridgeException $e) {
                    $unreachable = $e->isRetryable();
                    throw $e;
                }
                $output->writeln(sprintf(
                    '  <info>delivered</info> %s %s',
                    (string)($body['op'] ?? '?'),
                    (string)($body['target'] ?? '?'),
                ));
            },
            $limit,
            $timeout,
            $budget,
        );

        $output->writeln(sprintf(
            '<info>%d delivered · %d failed · %d still queued</info> (stopped: %s)',
            $result['sent'],
            $result['failed'],
            $result['remaining'],
            $result['stopped'],
        ));
        if ($result['remaining'] === 0) {
            return 0;
        }
        if ($unreachable || $result['stopped'] === 'unreachable') {
            $output->writeln(
                '<comment>Exit 2: the bridge is unreachable, so nothing was decided - run this command again once it is back '
                . '(systemctl status pages-bridge).</comment>',
            );

            return PushConfig::EXIT_RETRYABLE;
        }
        $output->writeln(
            '<comment>Exit 1: entries remain (the bridge refused them or the run hit its limit). '
            . 'Fix the cause it reported, or run the command again for the rest.</comment>',
        );

        return PushConfig::EXIT_FAILED;
    }
}
