<?php

declare(strict_types=1);

namespace OCA\Pages\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * One row of `pages_comments`: a comment on an article.
 *
 * Deletion is always soft — the row stays, `deleted_at` marks it — so a moderated thread keeps its
 * history and a comment can be hidden without losing what was said.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getArticleId()
 * @method void setArticleId(int $articleId)
 * @method string getAuthorUid()
 * @method void setAuthorUid(string $authorUid)
 * @method string getBody()
 * @method void setBody(string $body)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 * @method int getDeletedAt()
 * @method void setDeletedAt(int $deletedAt)
 */
class Comment extends Entity
{
    protected int $articleId = 0;
    protected string $authorUid = '';
    protected string $body = '';
    protected int $createdAt = 0;
    protected int $updatedAt = 0;
    /** 0 while the comment is visible, otherwise the moment it was deleted. */
    protected int $deletedAt = 0;

    public function __construct()
    {
        $this->addType('articleId', Types::BIGINT);
        $this->addType('authorUid', Types::STRING);
        $this->addType('body', Types::TEXT);
        $this->addType('createdAt', Types::BIGINT);
        $this->addType('updatedAt', Types::BIGINT);
        $this->addType('deletedAt', Types::BIGINT);
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt > 0;
    }
}
