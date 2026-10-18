<?php

declare(strict_types=1);

namespace OCA\Pages\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * One row of `pages_votes`: what one user thinks of one article.
 *
 * A voter has at most one row per article (the unique index on `(article_id, voter_uid)` enforces
 * it), so changing an opinion updates the row, and withdrawing a vote deletes it.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getArticleId()
 * @method void setArticleId(int $articleId)
 * @method string getVoterUid()
 * @method void setVoterUid(string $voterUid)
 * @method int getVote()
 * @method void setVote(int $vote)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class Vote extends Entity
{
    /** Upvote, the only positive value the `vote` column ever holds. */
    public const UP = 1;
    /** Downvote, the only negative value the `vote` column ever holds. */
    public const DOWN = -1;

    protected int $articleId = 0;
    protected string $voterUid = '';
    protected int $vote = 0;
    protected int $createdAt = 0;
    protected int $updatedAt = 0;

    public function __construct()
    {
        $this->addType('articleId', Types::BIGINT);
        $this->addType('voterUid', Types::STRING);
        $this->addType('vote', Types::SMALLINT);
        $this->addType('createdAt', Types::BIGINT);
        $this->addType('updatedAt', Types::BIGINT);
    }
}
