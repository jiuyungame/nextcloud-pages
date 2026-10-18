<?php

declare(strict_types=1);

namespace OCA\Pages\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One file the site has, as far as the app knows: the path inside the work copy, whose file it is,
 * and the commit that put it there.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getPath()
 * @method void setPath(string $path)
 * @method string getOwnerUid()
 * @method void setOwnerUid(string $ownerUid)
 * @method string getRevision()
 * @method void setRevision(string $revision)
 * @method int getPublishedAt()
 * @method void setPublishedAt(int $publishedAt)
 */
class Published extends Entity
{
    protected string $path = '';
    /** '' for a file of the site itself (a layout, the config, a post of the site's own `_posts/`). */
    protected string $ownerUid = '';
    protected string $revision = '';
    protected int $publishedAt = 0;

    public function __construct()
    {
        $this->addType('publishedAt', 'integer');
    }
}
