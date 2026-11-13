<?php
namespace OCA\Journeys\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * is_away: whether a journey left the home radius; NULL until known (rows from
 * before this column are classified lazily on first listing).
 * journeys_videos: which rendered file belongs to which album, so renaming a
 * journey does not lose its video.
 */
class Version0600Date20261106 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $changed = false;

        if ($schema->hasTable('journeys_cluster_albums')) {
            $table = $schema->getTable('journeys_cluster_albums');
            if (!$table->hasColumn('is_away')) {
                $table->addColumn('is_away', Types::SMALLINT, ['notnull' => false, 'default' => null]);
                $changed = true;
            }
        }

        if (!$schema->hasTable('journeys_videos')) {
            $t = $schema->createTable('journeys_videos');
            $t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $t->addColumn('user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
            $t->addColumn('album_id', Types::BIGINT, ['notnull' => true]);
            $t->addColumn('file_id', Types::BIGINT, ['notnull' => true]);
            $t->addColumn('orientation', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'portrait']);
            $t->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['user_id', 'album_id'], 'journeys_videos_user_album');
            $t->addUniqueIndex(['file_id'], 'journeys_videos_file');
            $changed = true;
        }

        return $changed ? $schema : null;
    }
}
