<?php

declare(strict_types=1);

namespace OCA\Pages\Command;

use OCA\Pages\Db\Article;
use OCA\Pages\Service\AuthorAreaService;
use OCA\Pages\Service\FileService;
use OCA\Pages\Service\ReviewStore;
use OCP\IL10N;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ pages:manifest` — the review manifest: which article is in which state, and what the site
 * serves.
 *
 * Read-only. The manifest lives in the app's own storage under appdata
 * (`appdata_<instanceid>/pages/review.json`), which the Files app does not show and nothing serves over
 * HTTP, so this is how an administrator inspects it - and `--json` prints the document itself, in the
 * bytes the app writes, which is what a backup needs.
 *
 * The state column is derived, exactly like the app derives it: a take-down request means `requested`,
 * otherwise `online/` makes an article `external` and the absence of it `internal`. The two content
 * directories the bridge writes are read here for that reason, and listed as their own counts. The
 * "source deleted" marker next to a take-down request is derived the same way the review screen derives
 * it: from the file that is there **now**, not from the flag the request recorded when it was filed
 * ({@see self::sourceDeleted()}).
 */
class Manifest extends Command
{
    public function __construct(
        private ReviewStore $store,
        private IL10N $l10n,
        private FileService $files,
        private AuthorAreaService $areas,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('pages:manifest')
            ->setDescription('Show the review manifest (article states and what the site serves)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the manifest as JSON, for a backup');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ((bool)$input->getOption('json')) {
            // the document as the app stores it: sorted keys, two-space indent, trailing newline
            $output->write($this->store->json());

            return 0;
        }

        $location = $this->store->location();
        $output->writeln($this->l10n->t('Review manifest: %s', [$location]));
        if (!$this->store->exists()) {
            // the same answer the app gives: no decisions were recorded yet, on this site
            $output->writeln($this->l10n->t('It is not there yet; the first review action (a request, a decision or a take-down) writes it.'));

            return 0;
        }

        $manifest = $this->store->read();
        $counts = array_fill_keys(Article::STATES, 0);
        $rows = [];
        foreach ($manifest['entries'] as $path => $entry) {
            $state = $this->store->stateIn($manifest, (string)$path);
            $counts[$state] = ($counts[$state] ?? 0) + 1;
            $rows[(string)$path] = [$state, $entry];
        }
        $served = $this->store->counts();
        $output->writeln($this->l10n->t(
            'Articles: %d (internal %d · requested %d · external %d)',
            [
                count($manifest['entries']),
                $counts[Article::STATE_INTERNAL],
                $counts[Article::STATE_REQUESTED],
                $counts[Article::STATE_EXTERNAL],
            ],
        ));
        $output->writeln($this->l10n->t(
            'Content directories: %d file(s) in online/, %d in pending/',
            [$served['online'], $served['pending']],
        ));

        if ($rows !== []) {
            $table = new Table($output);
            $table->setHeaders([
                $this->l10n->t('article'),
                $this->l10n->t('state'),
                $this->l10n->t('owner'),
                $this->l10n->t('take-offline'),
                $this->l10n->t('decided by'),
                $this->l10n->t('note'),
            ]);
            foreach ($rows as $path => [$state, $entry]) {
                $table->addRow([
                    $path,
                    $state,
                    (string)$entry['owner'],
                    self::downRequest($entry['downRequest'] ?? null, $this->sourceDeleted($path, $entry)),
                    (string)$entry['decidedBy'],
                    (string)$entry['note'],
                ]);
            }
            $table->render();
        }

        return 0;
    }

    /**
     * Whether the source behind one entry is gone **now**, which is the fact the decision reads.
     *
     * The manifest's `sourceDeleted` is what the delete path knew when it filed the request; the file
     * may have come back (or gone) since. One stat per entry that has a request and an author keeps the
     * CLI and the review screen on the same answer. A site-owned post (`owner` '') has no author area
     * to read, so the recorded flag is all there is for it.
     *
     * @param array<string,mixed> $entry
     */
    private function sourceDeleted(string $path, array $entry): bool
    {
        $request = $entry['downRequest'] ?? null;
        if (!is_array($request)) {
            return false;
        }
        $owner = trim((string)($entry['owner'] ?? ''));
        if ($owner === '') {
            return ($request['sourceDeleted'] ?? false) === true;
        }
        try {
            $resolved = $this->areas->resolve($owner, $path);
            if (!$resolved['ok']) {
                return true;
            }

            return !$this->files->fileExists($owner, $this->areas->area(), (string)$resolved['file']);
        } catch (\Throwable) {
            // a storage that cannot answer is not evidence that the file is there, and the decision
            // treats the same failure as "gone"; the column must not contradict it
            return true;
        }
    }

    /**
     * One take-down request as a table cell: the moment and whether the source is deleted now.
     * `-` is "nothing requested", which is what a null request means.
     */
    private static function downRequest(mixed $request, bool $sourceDeleted): string
    {
        if (!is_array($request)) {
            return '-';
        }
        $at = (int)($request['at'] ?? 0);

        return ($at > 0 ? date('Y-m-d H:i', $at) : '-') . ($sourceDeleted ? ' · source deleted' : '');
    }
}
