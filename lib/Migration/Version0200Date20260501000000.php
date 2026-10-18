<?php

declare(strict_types=1);

namespace OCA\Pages\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The app's first schema: the editorial state of an article, the comments under it and the votes on it.
 *
 * The site itself stays in the filesystem and in git — only the data that has no place in a working
 * copy lives here. Column names deliberately reuse the bridge's snake_case vocabulary, and every
 * `notnull` column whose empty value is meaningful carries that empty value as its default, so a row
 * inserted by any code path has the same shape.
 */
class Version0200Date20260501000000 extends SimpleMigrationStep
{
    /**
     * @param Closure():ISchemaWrapper $schemaClosure
     * @param array{tablePrefix?: string} $options
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // one row per article path; `owner_uid` is '' for an article nobody has claimed yet
        if (!$schema->hasTable('pages_articles')) {
            $table = $schema->createTable('pages_articles');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('owner_uid', Types::STRING, [
                'length' => 64,
                'notnull' => true,
                'default' => '',
            ]);
            $table->addColumn('path', Types::STRING, [
                'length' => 512,
                'notnull' => true,
            ]);
            $table->addColumn('title', Types::STRING, [
                'length' => 255,
                'notnull' => true,
                'default' => '',
            ]);
            $table->addColumn('tags', Types::STRING, [
                'length' => 512,
                'notnull' => true,
                'default' => '',
            ]);
            $table->addColumn('state', Types::STRING, [
                'length' => 16,
                'notnull' => true,
                'default' => 'draft',
            ]);
            $table->addColumn('review_note', Types::TEXT, [
                'notnull' => false,
            ]);
            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
            ]);
            $table->addColumn('updated_at', Types::BIGINT, [
                'notnull' => true,
            ]);
            $table->addColumn('submitted_at', Types::BIGINT, [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('published_at', Types::BIGINT, [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('published_revision', Types::STRING, [
                'length' => 64,
                'notnull' => true,
                'default' => '',
            ]);

            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['path'], 'pages_articles_path');
        }

        // comments are never hard-deleted: `deleted_at` keeps the thread readable for moderation
        if (!$schema->hasTable('pages_comments')) {
            $table = $schema->createTable('pages_comments');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('article_id', Types::BIGINT, [
                'notnull' => true,
            ]);
            $table->addColumn('author_uid', Types::STRING, [
                'length' => 64,
                'notnull' => true,
            ]);
            $table->addColumn('body', Types::TEXT, [
                'notnull' => true,
            ]);
            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
            ]);
            $table->addColumn('updated_at', Types::BIGINT, [
                'notnull' => true,
            ]);
            $table->addColumn('deleted_at', Types::BIGINT, [
                'notnull' => true,
                'default' => 0,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['article_id'], 'pages_comments_article_id');
            // the thread is always read as "comments of one article, oldest first"
            $table->addIndex(['article_id', 'created_at'], 'pages_comments_article');
        }

        // one row per (article, voter): the unique index is what makes a vote idempotent
        if (!$schema->hasTable('pages_votes')) {
            $table = $schema->createTable('pages_votes');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('article_id', Types::BIGINT, [
                'notnull' => true,
            ]);
            $table->addColumn('voter_uid', Types::STRING, [
                'length' => 64,
                'notnull' => true,
            ]);
            // +1 or -1; withdrawing a vote deletes the row instead of storing a 0
            $table->addColumn('vote', Types::SMALLINT, [
                'notnull' => true,
            ]);
            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
            ]);
            $table->addColumn('updated_at', Types::BIGINT, [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['article_id', 'voter_uid'], 'pages_votes_article_voter');
        }

        return $schema;
    }
}
