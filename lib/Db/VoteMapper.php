<?php

declare(strict_types=1);

namespace OCA\Pages\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Reads and writes the vote rows.
 *
 * Tallies are grouped in the database: a listing asks for the scores of the articles it shows, not
 * for the rows behind them.
 *
 * @template-extends QBMapper<Vote>
 */
class VoteMapper extends QBMapper
{
    private const TABLE = 'pages_votes';

    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, self::TABLE, Vote::class);
    }

    public function findForArticleAndVoter(int $articleId, string $voterUid): ?Vote
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('article_id', $qb->createNamedParameter($articleId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('voter_uid', $qb->createNamedParameter($voterUid, IQueryBuilder::PARAM_STR)));

        try {
            /** @var Vote $vote */
            $vote = $this->findEntity($qb);

            return $vote;
        } catch (DoesNotExistException|MultipleObjectsReturnedException) {
            return null;
        }
    }

    /**
     * Up and down counts for a list of articles, keyed by article id. An article nobody voted on is
     * absent from the map — read it as `$aggregates[$id] ?? ['up' => 0, 'down' => 0]`.
     *
     * @param array<int|string> $articleIds
     * @return array<int,array{up:int,down:int}>
     */
    public function aggregateForArticles(array $articleIds): array
    {
        $ids = $this->normalizeIds($articleIds);
        if ($ids === []) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('article_id', 'vote')
            ->selectAlias($qb->func()->count('id'), 'vote_count')
            ->from($this->getTableName())
            ->where($qb->expr()->in('article_id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
            ->groupBy('article_id')
            ->addGroupBy('vote');

        $result = $qb->executeQuery();
        try {
            $aggregates = [];
            while (($row = $result->fetch()) !== false) {
                $articleId = (int)$row['article_id'];
                $aggregates[$articleId] ??= ['up' => 0, 'down' => 0];
                $aggregates[$articleId][((int)$row['vote'] > 0) ? 'up' : 'down'] = (int)$row['vote_count'];
            }

            return $aggregates;
        } finally {
            $result->closeCursor();
        }
    }

    /**
     * What one voter did to a list of articles, keyed by article id: `+1`, `-1`, or absent when the
     * voter has no vote on that article.
     *
     * @param array<int|string> $articleIds
     * @return array<int,int>
     */
    public function findForArticlesAndVoter(array $articleIds, string $voterUid): array
    {
        $ids = $this->normalizeIds($articleIds);
        if ($ids === []) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('article_id', 'vote')
            ->from($this->getTableName())
            ->where($qb->expr()->in('article_id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($qb->expr()->eq('voter_uid', $qb->createNamedParameter($voterUid, IQueryBuilder::PARAM_STR)));

        $result = $qb->executeQuery();
        try {
            $votes = [];
            while (($row = $result->fetch()) !== false) {
                $votes[(int)$row['article_id']] = (int)$row['vote'];
            }

            return $votes;
        } finally {
            $result->closeCursor();
        }
    }

    /**
     * Sets, changes or withdraws one vote. `+1`/`-1` update the existing row (a second row for the
     * same voter and article is impossible), `0` withdraws the vote by deleting the row.
     */
    public function setVote(int $articleId, string $voterUid, int $vote): void
    {
        if ($vote === 0) {
            $qb = $this->db->getQueryBuilder();
            $qb->delete($this->getTableName())
                ->where($qb->expr()->eq('article_id', $qb->createNamedParameter($articleId, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('voter_uid', $qb->createNamedParameter($voterUid, IQueryBuilder::PARAM_STR)));
            $qb->executeStatement();

            return;
        }

        if ($vote !== Vote::UP && $vote !== Vote::DOWN) {
            throw new \InvalidArgumentException('A vote is +1, -1 or 0 (withdraw), got ' . $vote);
        }

        $existing = $this->findForArticleAndVoter($articleId, $voterUid);
        $now = time();

        if ($existing === null) {
            $qb = $this->db->getQueryBuilder();
            $qb->insert($this->getTableName())
                ->values([
                    'article_id' => $qb->createNamedParameter($articleId, IQueryBuilder::PARAM_INT),
                    'voter_uid' => $qb->createNamedParameter($voterUid, IQueryBuilder::PARAM_STR),
                    'vote' => $qb->createNamedParameter($vote, IQueryBuilder::PARAM_INT),
                    'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
                    'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
                ]);
            $qb->executeStatement();

            return;
        }

        // switching sides is an update; `created_at` keeps the moment the voter first voted
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('vote', $qb->createNamedParameter($vote, IQueryBuilder::PARAM_INT))
            ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($existing->getId(), IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    /**
     * @param array<int|string> $articleIds
     * @return list<int> ids as integers, without duplicates, as PARAM_INT_ARRAY requires
     */
    private function normalizeIds(array $articleIds): array
    {
        $ids = [];
        foreach ($articleIds as $articleId) {
            $ids[(int)$articleId] = true;
        }

        return array_keys($ids);
    }
}
