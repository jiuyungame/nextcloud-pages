<?php

declare(strict_types=1);

namespace OCA\Pages\Service;

use OCA\Pages\Db\Article;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IAppData;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * The one-shot move to the three-state visibility model (`internal` / `requested` / `external`).
 *
 * The old workflow had six content states and two request marks; the approved model has three states
 * and one intent. This class is the only place that rewrites what exists, once, at boot:
 *
 *   1. snapshot the manifest and both cache tables into the app's own storage;
 *   2. map the manifest entries (old state → new state, `reviewedBy` → `decidedBy`, `intent` for the
 *      entries that become `requested`, the two request marks dropped) and sync the caches;
 *   3. map the `state` column of `pages_articles` for the rows the manifest does not cover;
 *   4. write the flag **last**.
 *
 * The order is the guarantee. An interrupted run leaves the flag unset, so the next boot retries from
 * what is left; the mapping itself is idempotent (no new value is an old one), and a completed run is
 * a no-op forever after - the second start writes neither the manifest nor a table, which is what makes
 * the two byte-identical.
 *
 * The mapping is the approved one: draft → internal, submitted/approved → requested (intent online; the
 * author asked for a change of visibility and nobody has decided yet), published → external. The two
 * retired states follow the same "nothing is pending and the site does not have it" rule: rejected →
 * internal, offline → internal.
 */
final class VisibilityMigration
{
    /** The appconfig flag: once set, the migration never runs again. */
    public const FLAG = 'visibility_v1';

    /** The folder inside the app's own storage that holds the backup of one run. */
    public const BACKUP_PREFIX = 'visibility-';

    /** @var array<string,string> old state value => new state value */
    public const STATE_MAP = [
        'draft' => Article::STATE_INTERNAL,
        'submitted' => Article::STATE_REQUESTED,
        'approved' => Article::STATE_REQUESTED,
        'published' => Article::STATE_EXTERNAL,
        'rejected' => Article::STATE_INTERNAL,
        'offline' => Article::STATE_INTERNAL,
    ];

    /** @var array<string,string> old state value => intent of the entries that become `requested` */
    public const INTENT_MAP = [
        'submitted' => Article::INTENT_ONLINE,
        'approved' => Article::INTENT_ONLINE,
    ];

    /** The two cache tables, as the migration reads them for the snapshot and the mapping. */
    private const ARTICLES = 'pages_articles';
    private const PUBLISHED = 'pages_published';

    public function __construct(
        private IConfig $config,
        private ReviewStore $store,
        private IDBConnection $db,
        private IAppData $appData,
        private LoggerInterface $logger,
        private IL10N $l10n,
    ) {
    }

    /**
     * Runs the migration once. Never throws: this runs from boot(), and a request must not fail over
     * it; every problem is logged and leaves the flag unset so the next start retries.
     */
    public function run(): void
    {
        if ($this->config->getAppValue('pages', self::FLAG, '') === '1') {
            return;
        }
        try {
            $this->migrate();
        } catch (\Throwable $e) {
            $this->logger->error('pages: the visibility migration failed and will retry', ['exception' => $e]);
        }
    }

    /** @throws \Throwable when the snapshot cannot be written or a state cannot be mapped */
    private function migrate(): void
    {
        $articlesBefore = $this->rows(self::ARTICLES);
        $publishedBefore = $this->rows(self::PUBLISHED);
        $manifest = $this->store->rawJson();

        // the snapshot goes first: it is the only copy of what the mapping replaces
        $backup = $this->snapshot($manifest, $articlesBefore, $publishedBefore);

        $manifestOutcome = $this->store->migrateStates(self::STATE_MAP, self::INTENT_MAP);
        if ($manifestOutcome['changed'] > 0 && $manifestOutcome['written'] === false) {
            // somebody else wrote the manifest while this run held it: nothing is half-applied (the
            // write is all-or-nothing), and the flag stays unset so the next start retries
            throw new \RuntimeException($this->l10n->t('The review manifest was changed while it was being migrated; nothing was written.'));
        }
        $rows = $this->mapArticles();

        $after = $this->stateCounts($this->rows(self::ARTICLES));
        $report = [
            'backup' => $backup,
            'snapshot' => [
                'manifest_bytes' => strlen($manifest),
                'manifest_entries' => $manifestOutcome['entries'],
                'articles_rows' => count($articlesBefore),
                'published_rows' => count($publishedBefore),
            ],
            'manifest' => [
                'written' => $manifestOutcome['written'],
                'changed' => $manifestOutcome['changed'],
                'states' => $manifestOutcome['states'],
            ],
            'table' => $rows,
            'counts_before' => $this->stateCounts($articlesBefore),
            'counts_after' => $after,
        ];
        $this->snapshotReport($report);
        $this->logger->info('pages: visibility migration finished', $report);

        // the flag is written last: until it is there, every start tries again
        $this->config->setAppValue('pages', self::FLAG, '1');
    }

    /**
     * The manifest and both cache tables as they were, in one folder of the app's own storage. The path
     * is returned (and logged) so an administrator can find it; a snapshot that cannot be written
     * aborts the migration, because there would be no way back from the mapping.
     *
     * @param list<array<string,mixed>> $articles
     * @param list<array<string,mixed>> $published
     * @return string `appdata pages/visibility-<stamp>/`
     * @throws \RuntimeException when the folder cannot be created
     */
    private function snapshot(string $manifest, array $articles, array $published): string
    {
        $base = $this->appData->getFolder('/');
        $name = self::BACKUP_PREFIX . date('Ymd-His');
        $candidate = $name;
        $suffix = 1;
        while ($this->folderExists($base, $candidate)) {
            $suffix++;
            $candidate = $name . '-' . $suffix;
        }
        try {
            $folder = $base->newFolder($candidate);
            // the bytes as stored: the normalising read would not show the old state values
            $folder->newFile(ReviewStore::FILE, $manifest === '' ? "{}\n" : $manifest);
            $folder->newFile('pages_articles.json', self::json($articles));
            $folder->newFile('pages_published.json', self::json($published));
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                $this->l10n->t('The backup folder %s could not be written, so nothing was migrated: %s', [$candidate, $e->getMessage()]),
                0,
                $e,
            );
        }

        return 'appdata pages/' . $candidate . '/';
    }

    /**
     * The counts and the mapping outcome next to the snapshot, so the evidence of one run is readable
     * without the log. A report that cannot be written is logged and does not fail the migration: the
     * mapping is already applied, and the log carries the same numbers.
     *
     * @param array<string,mixed> $report
     */
    private function snapshotReport(array $report): void
    {
        $name = basename(trim((string)($report['backup'] ?? ''), ' /'));
        if ($name === '') {
            return;
        }
        try {
            $this->appData->getFolder('/')->getFolder($name)
                ->newFile('migration.json', self::json($report + ['flag' => self::FLAG, 'at' => date('c')]));
        } catch (\Throwable $e) {
            $this->logger->warning('pages: the visibility migration report could not be written next to the snapshot', [
                'folder' => $name,
                'exception' => $e,
            ]);
        }
    }

    /** Whether a folder of that name already exists in the app's own storage. */
    private function folderExists(ISimpleFolder $base, string $name): bool
    {
        try {
            $base->getFolder($name);

            return true;
        } catch (\OCP\Files\NotFoundException) {
            return false;
        }
    }

    /**
     * Maps the `state` column of the article table. The manifest sync already mapped the rows it knows
     * (see ReviewStore::syncCaches()), so this covers the rest - rows whose manifest entry is gone - and
     * it is idempotent either way: no new value is an old one.
     *
     * @return array{rows:int,changed:int,states:array<string,array<string,int>>}
     */
    private function mapArticles(): array
    {
        $rows = $this->rows(self::ARTICLES);
        $changed = 0;
        $states = [];
        foreach ($rows as $row) {
            $old = (string)($row['state'] ?? '');
            $new = self::STATE_MAP[$old] ?? $old;
            if ($new === $old) {
                continue;
            }
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $qb = $this->db->getQueryBuilder();
            $qb->update(self::ARTICLES)
                ->set('state', $qb->createNamedParameter($new))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
            $qb->executeStatement();
            $changed++;
            $states[$old][$new] = ($states[$old][$new] ?? 0) + 1;
        }

        return ['rows' => count($rows), 'changed' => $changed, 'states' => $states];
    }

    /**
     * Every row of one table as an array. `pages_articles` and `pages_published` are the app's own
     * tables, so the query builder prefixes them and no name comes from a request.
     *
     * @return list<array<string,mixed>>
     */
    private function rows(string $table): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($table);
        $result = $qb->executeQuery();
        try {
            return $result->fetchAllAssociative();
        } finally {
            $result->closeCursor();
        }
    }    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,int> state => count
     */
    private function stateCounts(array $rows): array
    {
        $counts = array_fill_keys(Article::STATES, 0);
        foreach ($rows as $row) {
            $state = (string)($row['state'] ?? '');
            $counts[$state] = ($counts[$state] ?? 0) + 1;
        }

        return $counts;
    }

    /** @param mixed $value */
    private static function json(mixed $value): string
    {
        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return ($json === false ? '{}' : $json) . "\n";
    }
}
