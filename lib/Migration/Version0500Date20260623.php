<?php
namespace OCA\Journeys\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Travel-diary schema (squashed). Creates the tables that back user-authored
 * journals, their daily entries, curated entry photos, and collaboration
 * membership. Independent of the clustering tracking table — a journal is
 * authored live during travel, before any "journey"/cluster is detected.
 */
class Version0500Date20260623 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // --- journeys_journals -----------------------------------------------
        if (!$schema->hasTable('journeys_journals')) {
            $t = $schema->createTable('journeys_journals');
            $t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $t->addColumn('user_id', 'string', ['length' => 64, 'notnull' => true]);
            $t->addColumn('title', 'text', ['notnull' => true]);
            $t->addColumn('description', 'text', ['notnull' => false]);
            $t->addColumn('cover_fileid', 'bigint', ['notnull' => false]);
            $t->addColumn('start_date', 'date', ['notnull' => false]);
            $t->addColumn('end_date', 'date', ['notnull' => false]);
            $t->addColumn('public_token', 'string', ['length' => 64, 'notnull' => false]);
            $t->addColumn('created_at', 'datetime', ['notnull' => false]);
            $t->addColumn('updated_at', 'datetime', ['notnull' => false]);
            $t->setPrimaryKey(['id'], 'jj_pk');
            $t->addIndex(['user_id'], 'jj_user_idx');
            $t->addUniqueIndex(['public_token'], 'jj_token_uidx');
        }

        // --- journeys_journal_entries ----------------------------------------
        if (!$schema->hasTable('journeys_journal_entries')) {
            $t = $schema->createTable('journeys_journal_entries');
            $t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $t->addColumn('journal_id', 'bigint', ['notnull' => true, 'unsigned' => true]);
            $t->addColumn('entry_date', 'date', ['notnull' => true]);
            $t->addColumn('title', 'text', ['notnull' => false]);
            $t->addColumn('body', 'text', ['notnull' => false]);
            $t->addColumn('sort_order', 'integer', ['notnull' => true, 'default' => 0]);
            $t->addColumn('author_uid', 'string', ['length' => 64, 'notnull' => false]);
            // Denormalized location cache, recomputed when the photo selection changes.
            $t->addColumn('lat', 'float', ['notnull' => false]);
            $t->addColumn('lon', 'float', ['notnull' => false]);
            $t->addColumn('place_label', 'text', ['notnull' => false]);
            $t->addColumn('city', 'text', ['notnull' => false]);
            $t->addColumn('country', 'text', ['notnull' => false]);
            $t->addColumn('country_code', 'string', ['length' => 2, 'notnull' => false]);
            $t->addColumn('created_at', 'datetime', ['notnull' => false]);
            $t->addColumn('updated_at', 'datetime', ['notnull' => false]);
            $t->setPrimaryKey(['id'], 'jje_pk');
            $t->addIndex(['journal_id', 'entry_date'], 'jje_journal_idx');
        }

        // --- journeys_entry_photos -------------------------------------------
        if (!$schema->hasTable('journeys_entry_photos')) {
            $t = $schema->createTable('journeys_entry_photos');
            $t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $t->addColumn('entry_id', 'bigint', ['notnull' => true, 'unsigned' => true]);
            $t->addColumn('fileid', 'bigint', ['notnull' => true]);
            // The user whose library the file lives in (the contributor who added it).
            $t->addColumn('owner_uid', 'string', ['length' => 64, 'notnull' => false]);
            $t->addColumn('sort_order', 'integer', ['notnull' => true, 'default' => 0]);
            $t->addColumn('caption', 'text', ['notnull' => false]);
            $t->setPrimaryKey(['id'], 'jep_pk');
            $t->addIndex(['entry_id'], 'jep_entry_idx');
        }

        // --- journeys_journal_members (collaboration) ------------------------
        if (!$schema->hasTable('journeys_journal_members')) {
            $t = $schema->createTable('journeys_journal_members');
            $t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $t->addColumn('journal_id', 'bigint', ['notnull' => true, 'unsigned' => true]);
            $t->addColumn('principal_type', 'string', ['length' => 8, 'notnull' => true]);
            $t->addColumn('principal_id', 'string', ['length' => 64, 'notnull' => true]);
            $t->addColumn('created_at', 'datetime', ['notnull' => false]);
            $t->setPrimaryKey(['id'], 'jjm_pk');
            $t->addUniqueIndex(['journal_id', 'principal_type', 'principal_id'], 'jjm_uidx');
            $t->addIndex(['principal_id', 'principal_type'], 'jjm_principal_idx');
        }

        return $schema;
    }
}
