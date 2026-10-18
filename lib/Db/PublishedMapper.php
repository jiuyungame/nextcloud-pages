<?php

declare(strict_types=1);

namespace OCA\Pages\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The published-file list: what the last commit put on the site.
 *
 * @template-extends QBMapper<Published>
 */
class PublishedMapper extends QBMapper
{
    private const TABLE = 'pages_published';

    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, self::TABLE, Published::class);
    }

    /**
     * Every published path, keyed by path: the shape the reading list and the publish step want.
     *
     * @return array<string,Published>
     */
    public function allByPath(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())->orderBy('path', 'ASC');
        $out = [];
        foreach ($this->findEntities($qb) as $row) {
            $out[(string)$row->getPath()] = $row;
        }

        return $out;
    }

    public function findByPath(string $path): ?Published
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('path', $qb->createNamedParameter($path, IQueryBuilder::PARAM_STR)));

        return $this->findOneOrNull($qb);
    }

    /**
     * Records that these paths are on the site as of `$revision`. An entry that is already there keeps
     * its row (and its id) and only learns the new revision, so the list stays a set of files rather
     * than a log.
     *
     * @param array<string,string> $paths path => owner uid ('' for the site's own files)
     */
    public function record(array $paths, string $revision): int
    {
        $now = time();
        $written = 0;
        foreach ($paths as $path => $ownerUid) {
            $path = ltrim(trim((string)$path), '/');
            if ($path === '') {
                continue;
            }
            $existing = $this->findByPath($path);
            if ($existing === null) {
                $row = new Published();
                $row->setPath($path);
                $row->setOwnerUid(trim((string)$ownerUid));
                $row->setRevision($revision);
                $row->setPublishedAt($now);
                $this->insert($row);
                $written++;

                continue;
            }
            if ((string)$existing->getRevision() === $revision && (string)$existing->getOwnerUid() === trim((string)$ownerUid)) {
                continue;
            }
            $existing->setRevision($revision);
            $existing->setOwnerUid(trim((string)$ownerUid));
            $existing->setPublishedAt($now);
            $this->update($existing);
            $written++;
        }

        return $written;
    }

    /**
     * Forgets paths: they are not on the site any more (deleted, renamed, or taken down).
     *
     * @param list<string> $paths
     * @return list<string> the paths that were actually on the list
     */
    public function forget(array $paths): array
    {
        $removed = [];
        foreach ($paths as $path) {
            $path = ltrim(trim((string)$path), '/');
            if ($path === '') {
                continue;
            }
            $row = $this->findByPath($path);
            if ($row === null) {
                continue;
            }
            $this->delete($row);
            $removed[] = $path;
        }

        return $removed;
    }

    public function countAll(): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*'))->from($this->getTableName());

        return (int)$qb->executeQuery()->fetchOne();
    }

    /**
     * A lookup answers "is this path on the list", so a missing row is null rather than an exception;
     * a duplicated row would be a broken unique index, which reads as "not found" too.
     */
    private function findOneOrNull(IQueryBuilder $qb): ?Published
    {
        try {
            /** @var Published $row */
            $row = $this->findEntity($qb);

            return $row;
        } catch (DoesNotExistException|MultipleObjectsReturnedException) {
            return null;
        }
    }
}
