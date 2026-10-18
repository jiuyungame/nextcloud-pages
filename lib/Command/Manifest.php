<?php

declare(strict_types=1);

namespace OCA\Pages\Command;

use OCA\Pages\Db\Article;
use OCA\Pages\Service\ReviewStore;
use OCP\IL10N;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ pages:manifest` — the review manifest: which article is in which state, and which files the site
 * has.
 *
 * Read-only. The manifest lives in the app's own storage under appdata
 * (`appdata_<instanceid>/pages/review.json`), which the Files app does not show and nothing serves over
 * HTTP, so this is how an administrator inspects it - and `--json` prints the document itself, in the
 * bytes the app writes, which is what a backup needs.
 */
class Manifest extends Command
{
    public function __construct(
        private ReviewStore $store,
        private IL10N $l10n,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('pages:manifest')
            ->setDescription('Show the review manifest (article states and published files)')
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
            $output->writeln($this->l10n->t('It is not there yet; the first review action (submit, approve or publish) writes it.'));

            return 0;
        }

        $manifest = $this->store->read();
        $counts = array_fill_keys(Article::STATES, 0);
        foreach ($manifest['entries'] as $entry) {
            $state = (string)$entry['state'];
            $counts[$state] = ($counts[$state] ?? 0) + 1;
        }
        $output->writeln($this->l10n->t('Last written: %s', [self::stamp((int)$manifest['updatedAt'])]));
        $output->writeln($this->l10n->t(
            'Articles: %d (internal %d · requested %d · external %d)',
            [
                count($manifest['entries']),
                $counts[Article::STATE_INTERNAL],
                $counts[Article::STATE_REQUESTED],
                $counts[Article::STATE_EXTERNAL],
            ],
        ));
        $output->writeln($this->l10n->t('Published files: %d', [count($manifest['published'])]));

        if ($manifest['entries'] !== []) {
            $table = new Table($output);
            $table->setHeaders([
                $this->l10n->t('article'),
                $this->l10n->t('state'),
                $this->l10n->t('owner'),
                $this->l10n->t('intent'),
                $this->l10n->t('decided by'),
                $this->l10n->t('note'),
            ]);
            foreach ($manifest['entries'] as $path => $entry) {
                $table->addRow([
                    $path,
                    (string)$entry['state'],
                    (string)$entry['owner'],
                    (string)$entry['intent'],
                    (string)$entry['decidedBy'],
                    (string)$entry['note'],
                ]);
            }
            $table->render();
        }

        if ($manifest['published'] !== []) {
            $table = new Table($output);
            $table->setHeaders([
                $this->l10n->t('published file'),
                $this->l10n->t('owner'),
                $this->l10n->t('revision'),
                $this->l10n->t('published at'),
            ]);
            foreach ($manifest['published'] as $path => $entry) {
                $table->addRow([
                    $path,
                    (string)$entry['owner'],
                    (string)$entry['revision'],
                    self::stamp((int)$entry['publishedAt']),
                ]);
            }
            $table->render();
        }

        return 0;
    }

    /** A moment as a person reads it; the manifest stores seconds since the epoch. */
    private static function stamp(int $timestamp): string
    {
        return $timestamp > 0 ? date('Y-m-d H:i', $timestamp) : '-';
    }
}
