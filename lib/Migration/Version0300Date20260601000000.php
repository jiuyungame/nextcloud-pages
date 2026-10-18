<?php

declare(strict_types=1);

namespace OCA\Pages\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The published-file list.
 *
 * Which files the site actually has is a fact, not an inference: the app is the only thing that can
 * put an author's file into a commit, so every successful publish records the site-relative paths it
 * committed here, with the commit that carries them. Deleting an entry means the file is not on the
 * site any more.
 *
 * Keeping this list separate from the review state is deliberate. `pages_articles.state` answers
 * "where is this article in the workflow", and that changes when somebody edits it - but an edited
 * article is still on the site until a new revision replaces it. The two questions have two answers,
 * so they have two homes.
 */
class Version0300Date20260601000000 extends SimpleMigrationStep
{
    /**
     * @param Closure():ISchemaWrapper $schemaClosure
     * @param array{tablePrefix?: string} $options
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('pages_published')) {
            $table = $schema->createTable('pages_published');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            // the site-relative path exactly as it appears in the work copy
            // (`_posts/<uid>/<file>.md`, `index.md`, ...)
            $table->addColumn('path', Types::STRING, [
                'length' => 512,
                'notnull' => true,
                'default' => '',
            ]);
            // the author whose file this is, '' for the site's own files
            $table->addColumn('owner_uid', Types::STRING, [
                'length' => 64,
                'notnull' => true,
                'default' => '',
            ]);
            // the commit that put the file on the site last ('' when git had nothing to commit)
            $table->addColumn('revision', Types::STRING, [
                'length' => 64,
                'notnull' => true,
                'default' => '',
            ]);
            $table->addColumn('published_at', Types::BIGINT, [
                'notnull' => true,
                'default' => 0,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['path'], 'pages_published_path');
            $table->addIndex(['owner_uid'], 'pages_published_owner');
        }

        return $schema;
    }
}
