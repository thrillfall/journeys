<?php
namespace OCA\Journeys\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version0403Date20250906 extends SimpleMigrationStep {
    /**
     * Add cluster boundary columns to journeys_cluster_albums to support incremental clustering
     *
     * @param IOutput $output
     * @param Closure $schemaClosure returns ISchemaWrapper
     * @param array $options
     * @return ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $tableName = 'journeys_cluster_albums';
        if ($schema->hasTable($tableName)) {
            $table = $schema->getTable($tableName);
            if (!$table->hasColumn('start_dt')) {
                $table->addColumn('start_dt', 'datetime', [ 'notnull' => false, 'default' => null ]);
            }
            if (!$table->hasColumn('end_dt')) {
                $table->addColumn('end_dt', 'datetime', [ 'notnull' => false, 'default' => null ]);
            }
        } else {
            // Create the table (should already exist since Version0401), but handle cold installs gracefully
            $table = $schema->createTable($tableName);
            $table->addColumn('user_id', 'string', [ 'length' => 64, 'notnull' => true ]);
            $table->addColumn('album_id', 'bigint', [ 'notnull' => true ]);
            $table->addColumn('name', 'text', [ 'notnull' => true ]);
            $table->addColumn('location', 'text', [ 'notnull' => false ]);
            $table->addColumn('start_dt', 'datetime', [ 'notnull' => false, 'default' => null ]);
            $table->addColumn('end_dt', 'datetime', [ 'notnull' => false, 'default' => null ]);
            $table->setPrimaryKey(['user_id', 'album_id'], 'jca_pk');
        }

        return $schema;
    }
}
