<?php

declare(strict_types=1);

namespace OCA\Pages\Command;

use OCA\Pages\Service\ArticleIndexService;
use OCA\Pages\Service\AuthorAreaService;
use OCA\Pages\Service\AuthorSourcesService;
use OCA\Pages\Service\BridgeClient;
use OCA\Pages\Service\BridgeException;
use OCA\Pages\Service\SiteRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ pages:repair` — re-derives the data that is derived, instead of asking an administrator to open
 * and re-save every page.
 *
 * Three derivations exist, and each already has an owner (this command invents no state, it re-runs
 * them):
 *
 *  1. the **article rows** — a cache over the author files, written by the save path through
 *     {@see ArticleIndexService::register()}; a file that arrived some other way (the Files app, a sync
 *     client, a restored backup) has no row and is therefore missing from every list. The scan walks the
 *     same entry list a push walks ({@see AuthorSourcesService::scan()}) and registers each file.
 *  2. the **mirror target lists** — what the last accepted push handed to the bridge, written only after
 *     the bridge accepted it; an interrupted save leaves them behind, and the next push would then
 *     neither add nor remove the file. Re-pushing recomputes them from what is really there.
 *  3. the **preview tree** — the bridge's build output; a forced build regenerates it, which is the only
 *     "rebuild marker" there is (the bridge owns the tree, the app only asks).
 *
 * The exit codes are the same taxonomy as `pages:push`: `0` repaired, `1` a check or a refused call
 * failed, `2` the bridge is unreachable and the command can simply be run again.
 */
class Repair extends Command
{
    public function __construct(
        private SiteRegistry $registry,
        private BridgeClient $bridge,
        private AuthorSourcesService $authorSources,
        private ArticleIndexService $index,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('pages:repair')
            ->setDescription('Re-derive the article index, the mirror target lists and the preview tree (exit 2 = bridge unreachable)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->registry->getSite() === null) {
            $output->writeln('<error>No site is configured yet; there is nothing to repair.</error>');

            return PushConfig::EXIT_FAILED;
        }

        // ---- 1. the author content scan: every mirrored file of every area, registered or refreshed ----
        $scan = $this->authorSources->scan();
        $registered = 0;
        $area = AuthorAreaService::AREA;
        foreach ($scan['entries'] as $entry) {
            try {
                $this->index->register($entry['uid'], $area, $entry['path'], $entry['target']);
                $registered++;
            } catch (\Throwable $e) {
                // one unreadable file must not stop the repair of the others, but it must be said out loud
                $output->writeln(sprintf('<comment>%s: %s could not be read: %s</comment>', $entry['uid'], $entry['path'], $e->getMessage()));
            }
        }
        $output->writeln(sprintf(
            '<info>author content scan</info> %d file(s) in %d author area(s), %d row(s) registered or refreshed',
            count($scan['entries']),
            count(array_unique(array_map(static fn (array $entry): string => (string)$entry['uid'], $scan['entries']))),
            $registered,
        ));
        foreach ($scan['errors'] as $uid => $message) {
            $output->writeln(sprintf('<error>author area %s could not be read: %s</error>', (string)$uid, $message));
        }

        // ---- 2. the mirror: re-push what the areas really hold, which recomputes both target lists ----
        $pending = $this->authorSources->pending($scan);
        $output->writeln(sprintf(
            '<info>mirror</info> %d target(s) the bridge has not been told about, %d target(s) it still holds from an earlier push',
            $pending['added'],
            $pending['removed'],
        ));
        try {
            $pushed = $this->authorSources->pushWithAuthors($this->bridge);
        } catch (BridgeException $e) {
            $this->registry->recordPushFailure([
                'author' => '',
                'code' => $e->getBridgeCode(),
                'message' => $e->getMessage(),
                'retryable' => $e->isRetryable(),
            ]);
            $output->writeln('<error>' . $e->getBridgeCode() . ': ' . $e->getMessage() . '</error>');
            $output->writeln($e->isRetryable()
                ? '<comment>The bridge is unreachable: nothing was decided, so run the same command again once it is back.</comment>'
                : '<comment>The bridge refused this configuration: fix the reported cause first, a retry cannot help.</comment>');

            return $e->isRetryable() ? PushConfig::EXIT_RETRYABLE : PushConfig::EXIT_FAILED;
        }
        $this->registry->recordPushFailure(null);
        $output->writeln(sprintf(
            '<info>mirror pushed</info> authors=%d revision=%s',
            (int)($pushed['authors'] ?? 0),
            (string)($pushed['result']['revision'] ?? '?'),
        ));
        foreach (($pushed['errors'] ?? []) as $uid => $message) {
            $output->writeln(sprintf('<comment>author %s could not be read: %s</comment>', (string)$uid, (string)$message));
        }

        // ---- 3. the preview tree: the bridge rebuilds it, a forced build is the marker ----
        try {
            $result = $this->bridge->build(true);
        } catch (BridgeException $e) {
            $output->writeln('<error>rebuild failed: ' . $e->getBridgeCode() . ': ' . $e->getMessage() . '</error>');

            return $e->isRetryable() ? PushConfig::EXIT_RETRYABLE : PushConfig::EXIT_FAILED;
        }
        // the bridge answers {ok, mirror, build}: a mirror that failed leaves `build` null, and saying
        // "rebuilt" then would be a lie
        $build = is_array($result['build'] ?? null) ? $result['build'] : null;
        $ok = ($result['ok'] ?? false) === true;
        if ($build === null) {
            $output->writeln(sprintf(
                '<error>preview tree not rebuilt</error> the bridge could not mirror the source (ok=%s)',
                $ok ? 'true' : 'false',
            ));

            return PushConfig::EXIT_FAILED;
        }
        $output->writeln(sprintf(
            '<info>preview tree rebuilt</info> %s · build_id %s · %s output file(s) in %s ms',
            $ok ? 'ok' : 'FAILED',
            (string)($build['build_id'] ?? '?'),
            (string)($build['output_files'] ?? '?'),
            (string)($build['ms'] ?? '?'),
        ));

        return $ok ? 0 : PushConfig::EXIT_FAILED;
    }
}
