<?php

declare(strict_types=1);

namespace OCA\Pages\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Reads and writes the article rows.
 *
 * Timestamps are written by the mapper, never by the caller: an article stays sortable and an
 * "updated" column never has to be trusted to the request that caused the change. Partial writes are
 * deliberate — `upsertByPath()` and `saveState()` name the columns they touch, so refreshing a title
 * cannot silently reset `state` or a publication timestamp.
 *
 * @template-extends QBMapper<Article>
 */
class ArticleMapper extends QBMapper
{
    private const TABLE = 'pages_articles';

    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, self::TABLE, Article::class);
    }

    public function findById(int $id): ?Article
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        return $this->findOneOrNull($qb);
    }

    public function findByPath(string $path): ?Article
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('path', $qb->createNamedParameter($path, IQueryBuilder::PARAM_STR)));

        return $this->findOneOrNull($qb);
    }

    /**
     * @return list<Article> newest first
     */
    public function findByOwner(string $uid): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('owner_uid', $qb->createNamedParameter($uid, IQueryBuilder::PARAM_STR)))
            ->orderBy('created_at', 'DESC')
            // id breaks ties, so that two articles written in the same second still have a stable order
            ->addOrderBy('id', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * @return list<Article> newest first
     */
    public function findAll(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('created_at', 'DESC')
            ->addOrderBy('id', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * @return list<Article> newest first
     */
    public function findByState(string $state): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('state', $qb->createNamedParameter($state, IQueryBuilder::PARAM_STR)))
            ->orderBy('created_at', 'DESC')
            ->addOrderBy('id', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * Indexes one file. A new article starts as `internal` — it is created, not approved for the site.
     * An existing article only has its editable fields refreshed: `state`, `created_at`,
     * `submitted_at`, `published_at` and `published_revision` belong to the visibility workflow and
     * are never written here.
     */
    public function upsertByPath(string $path, string $ownerUid, string $title, string $tags): Article
    {
        $existing = $this->findByPath($path);
        $now = time();

        if ($existing === null) {
            // every notnull column is named explicitly: the row must not depend on column defaults
            $qb = $this->db->getQueryBuilder();
            $qb->insert($this->getTableName())
                ->values([
                    'owner_uid' => $qb->createNamedParameter($ownerUid, IQueryBuilder::PARAM_STR),
                    'path' => $qb->createNamedParameter($path, IQueryBuilder::PARAM_STR),
                    'title' => $qb->createNamedParameter($title, IQueryBuilder::PARAM_STR),
                    'tags' => $qb->createNamedParameter($tags, IQueryBuilder::PARAM_STR),
                    'state' => $qb->createNamedParameter(Article::STATE_INTERNAL, IQueryBuilder::PARAM_STR),
                    'review_note' => $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
                    'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
                    'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
                    'submitted_at' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
                    'published_at' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
                    'published_revision' => $qb->createNamedParameter('', IQueryBuilder::PARAM_STR),
                ]);
            $qb->executeStatement();

            return $this->findById($qb->getLastInsertId())
                ?? throw new \RuntimeException('The article row disappeared while it was being inserted: ' . $path);
        }

        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('title', $qb->createNamedParameter($title, IQueryBuilder::PARAM_STR))
            ->set('tags', $qb->createNamedParameter($tags, IQueryBuilder::PARAM_STR))
            ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($existing->getId(), IQueryBuilder::PARAM_INT)));

        if ($existing->getOwnerUid() === '') {
            // an unowned (legacy) article belongs to the first user that indexes it
            $qb->set('owner_uid', $qb->createNamedParameter($ownerUid, IQueryBuilder::PARAM_STR));
        }

        $qb->executeStatement();

        return $this->findById($existing->getId())
            ?? throw new \RuntimeException('The article row disappeared while it was being updated: ' . $path);
    }

    /**
     * Moves an article through the review workflow.
     *
     * `state` and `review_note` are always written (`$note = ''` clears the note). The trailing
     * timestamps are only written when they carry a value, so publishing an article keeps the moment
     * it was submitted, and resubmitting it keeps the moment it was published before.
     */
    public function saveState(
        int $id,
        string $state,
        string $note = '',
        int $submittedAt = 0,
        int $publishedAt = 0,
        string $publishedRevision = '',
    ): Article {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('state', $qb->createNamedParameter($state, IQueryBuilder::PARAM_STR))
            ->set('review_note', $note === ''
                ? $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL)
                : $qb->createNamedParameter($note, IQueryBuilder::PARAM_STR))
            ->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        if ($submittedAt > 0) {
            $qb->set('submitted_at', $qb->createNamedParameter($submittedAt, IQueryBuilder::PARAM_INT));
        }
        if ($publishedAt > 0) {
            $qb->set('published_at', $qb->createNamedParameter($publishedAt, IQueryBuilder::PARAM_INT));
        }
        if ($publishedRevision !== '') {
            $qb->set('published_revision', $qb->createNamedParameter($publishedRevision, IQueryBuilder::PARAM_STR));
        }

        $qb->executeStatement();

        return $this->findById($id)
            ?? throw new \InvalidArgumentException('No such article: ' . $id);
    }

    /** Deletes the article row itself; comments and votes of that article are left to the caller. */
    public function deleteById(int $id): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    public function countAll(): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('id'))
            ->from($this->getTableName());

        $result = $qb->executeQuery();
        try {
            return (int)$result->fetchOne();
        } finally {
            $result->closeCursor();
        }
    }

    /**
     * A lookup returns null instead of an exception: `findById()`/`findByPath()` answer "is there such
     * an article", and a missing row is an ordinary answer. A duplicated row would be a broken unique
     * index rather than a request error, so it reads as "not found" too instead of a 500.
     */
    private function findOneOrNull(IQueryBuilder $qb): ?Article
    {
        try {
            /** @var Article $article */
            $article = $this->findEntity($qb);

            return $article;
        } catch (DoesNotExistException|MultipleObjectsReturnedException) {
            return null;
        }
    }
}
