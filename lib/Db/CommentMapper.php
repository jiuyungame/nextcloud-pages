<?php

declare(strict_types=1);

namespace OCA\Pages\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Reads and writes the comment rows.
 *
 * Counting is done in the database and grouped by article: a listing must not load a thread just to
 * know how long it is.
 *
 * @template-extends QBMapper<Comment>
 */
class CommentMapper extends QBMapper
{
    private const TABLE = 'pages_comments';

    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, self::TABLE, Comment::class);
    }

    /**
     * @return list<Comment> oldest first
     */
    public function findForArticle(int $articleId, bool $includeDeleted = false): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('article_id', $qb->createNamedParameter($articleId, IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'ASC')
            // id keeps two comments written in the same second in the order they were written
            ->addOrderBy('id', 'ASC');

        if (!$includeDeleted) {
            $qb->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
        }

        return $this->findEntities($qb);
    }

    /**
     * Comment counts for a list of articles, keyed by article id. Deleted comments are not counted,
     * and an article without comments is absent from the map — read it as `$counts[$id] ?? 0`.
     *
     * @param array<int|string> $articleIds
     * @return array<int,int>
     */
    public function countForArticles(array $articleIds): array
    {
        $ids = $this->normalizeIds($articleIds);
        if ($ids === []) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('article_id')
            ->selectAlias($qb->func()->count('id'), 'comment_count')
            ->from($this->getTableName())
            ->where($qb->expr()->in('article_id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->groupBy('article_id');

        $result = $qb->executeQuery();
        try {
            $counts = [];
            while (($row = $result->fetch()) !== false) {
                $counts[(int)$row['article_id']] = (int)$row['comment_count'];
            }

            return $counts;
        } finally {
            $result->closeCursor();
        }
    }

    /**
     * How many comments one author wrote on one article in a time window.
     *
     * Deleted rows are counted: a comment that was written and then deleted still used up one of the
     * author's turns, and a limit that forgets them could be reset by deleting one's own comments.
     */
    public function countForAuthorSince(int $articleId, string $authorUid, int $since): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('id'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('article_id', $qb->createNamedParameter($articleId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('author_uid', $qb->createNamedParameter($authorUid, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        try {
            return (int)$result->fetchOne();
        } finally {
            $result->closeCursor();
        }
    }

    /**
     * When an author last commented on an article, or null when they never did. The cooldown between
     * two comments is measured from this moment, so no second table is needed to remember it.
     */
    public function newestCreatedAtForAuthor(int $articleId, string $authorUid): ?int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('created_at')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('article_id', $qb->createNamedParameter($articleId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('author_uid', $qb->createNamedParameter($authorUid, IQueryBuilder::PARAM_STR)))
            // both columns are named explicitly: the "newest" of two comments written in the very same
            // second must be the one inserted second, not an arbitrary one of the two
            ->orderBy('created_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            // one row is all this asks for: no need to load a long thread to read its first line
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        try {
            $value = $result->fetchOne();

            return $value === false ? null : (int)$value;
        } finally {
            $result->closeCursor();
        }
    }

    /**
     * When the oldest comment of a window was written, or null when the window is empty. Counted
     * together with {@see countForAuthorSince()}, it turns the limit into a sliding window: the wait is
     * until that oldest comment falls out of it.
     */
    public function oldestCreatedAtSince(int $articleId, string $authorUid, int $since): ?int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('created_at')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('article_id', $qb->createNamedParameter($articleId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('author_uid', $qb->createNamedParameter($authorUid, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'ASC')
            ->addOrderBy('id', 'ASC')
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        try {
            $value = $result->fetchOne();

            return $value === false ? null : (int)$value;
        } finally {
            $result->closeCursor();
        }
    }

    public function add(int $articleId, string $authorUid, string $body): Comment
    {
        $now = time();

        // every notnull column is named explicitly: an empty body is still a row, and it must not be
        // left out of the INSERT because it happens to equal the property default
        $qb = $this->db->getQueryBuilder();
        $qb->insert($this->getTableName())
            ->values([
                'article_id' => $qb->createNamedParameter($articleId, IQueryBuilder::PARAM_INT),
                'author_uid' => $qb->createNamedParameter($authorUid, IQueryBuilder::PARAM_STR),
                'body' => $qb->createNamedParameter($body, IQueryBuilder::PARAM_STR),
                'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
                'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
                'deleted_at' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
            ]);
        $qb->executeStatement();

        return $this->findById($qb->getLastInsertId())
            ?? throw new \RuntimeException('The comment row disappeared while it was being inserted');
    }

    public function updateBody(int $id, string $body): Comment
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('body', $qb->createNamedParameter($body, IQueryBuilder::PARAM_STR))
            ->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();

        return $this->findById($id)
            ?? throw new \InvalidArgumentException('No such comment: ' . $id);
    }

    /** Deleting twice keeps the first deletion time, so a moderation log stays truthful. */
    public function softDelete(int $id): void
    {
        $now = time();

        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('deleted_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    /** One comment by id, or null when no row carries that id. */
    public function findById(int $id): ?Comment
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        try {
            /** @var Comment $comment */
            $comment = $this->findEntity($qb);

            return $comment;
        } catch (DoesNotExistException|MultipleObjectsReturnedException) {
            return null;
        }
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
