<?php

declare(strict_types=1);

namespace OCA\Pages\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * One row of `pages_articles`: the visibility state of a single path in the site tree.
 *
 * A page has exactly three states, and the review decides only *whether it goes live*:
 *
 *   internal   the site does not have it (default); visible and editable inside the app only
 *   requested  an author asked for a change of visibility and waits for a reviewer (`intent`)
 *   external   approved for the site; the next publish puts it there
 *
 * The entity is a read model. The mapper writes rows with explicit queries, so a partial update can
 * never touch a column the caller did not name. Property names reach the snake_case columns through
 * {@see Entity::propertyToColumn()} (`ownerUid` <-> `owner_uid`).
 *
 * The manifest ({@see \OCA\Pages\Service\ReviewStore}) is the truth about a state and about the
 * `intent`; this row is its cache. `reviewNote` carries the note of a rejection and is cleared by
 * every later request, exactly as the manifest does it.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getOwnerUid()
 * @method void setOwnerUid(string $ownerUid)
 * @method string getPath()
 * @method void setPath(string $path)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string getTags()
 * @method void setTags(string $tags)
 * @method string getState()
 * @method void setState(string $state)
 * @method string|null getReviewNote()
 * @method void setReviewNote(string|null $reviewNote)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 * @method int getSubmittedAt()
 * @method void setSubmittedAt(int $submittedAt)
 * @method int getPublishedAt()
 * @method void setPublishedAt(int $publishedAt)
 * @method string getPublishedRevision()
 * @method void setPublishedRevision(string $publishedRevision)
 */
class Article extends Entity
{
    /** The site does not have it: visible and editable inside the app only. The default. */
    public const STATE_INTERNAL = 'internal';
    /** The author asked for a change of visibility; the reviewer has not decided yet. */
    public const STATE_REQUESTED = 'requested';
    /** Approved for the site: the next publish puts it there. */
    public const STATE_EXTERNAL = 'external';

    /** @var list<string> every value `state` may hold */
    public const STATES = [
        self::STATE_INTERNAL,
        self::STATE_REQUESTED,
        self::STATE_EXTERNAL,
    ];

    /** `intent` of a request: the author wants the entry to go live. */
    public const INTENT_ONLINE = 'online';
    /** `intent` of a request: the author wants the entry taken off the site. */
    public const INTENT_OFFLINE = 'offline';

    /** @var list<string> every value `intent` may hold while `state` is `requested` */
    public const INTENTS = [
        self::INTENT_ONLINE,
        self::INTENT_OFFLINE,
    ];

    /** '' identifies an article nobody owns yet (legacy rows and first sight of a file). */
    protected string $ownerUid = '';
    protected string $path = '';
    protected string $title = '';
    /** Space-joined tag cache: the front matter of the file stays the source of truth. */
    protected string $tags = '';
    protected string $state = self::STATE_INTERNAL;
    /** Why the last decision turned the request down; null while there is nothing to say. */
    protected ?string $reviewNote = null;
    protected int $createdAt = 0;
    protected int $updatedAt = 0;
    /** The moment the pending request was filed (0 while nothing is requested). */
    protected int $submittedAt = 0;
    protected int $publishedAt = 0;
    protected string $publishedRevision = '';

    public function __construct()
    {
        $this->addType('ownerUid', Types::STRING);
        $this->addType('path', Types::STRING);
        $this->addType('title', Types::STRING);
        $this->addType('tags', Types::STRING);
        $this->addType('state', Types::STRING);
        $this->addType('reviewNote', Types::TEXT);
        $this->addType('createdAt', Types::BIGINT);
        $this->addType('updatedAt', Types::BIGINT);
        $this->addType('submittedAt', Types::BIGINT);
        $this->addType('publishedAt', Types::BIGINT);
        $this->addType('publishedRevision', Types::STRING);
    }
}
