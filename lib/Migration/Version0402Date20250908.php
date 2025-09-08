<?php
namespace OCA\Journeys\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version0402Date20250908 extends SimpleMigrationStep {
    /**
     * Add missing start_dt and end_dt columns and an index for (user_id, end_dt)
     * to the journeys_cluster_albums tracking table.
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
            // Add columns if they don't exist yet
            if (!$table->hasColumn('start_dt')) {
                $table->addColumn('start_dt', 'datetime', [
                    'notnull' => false,
                ]);
            }
            if (!$table->hasColumn('end_dt')) {
                $table->addColumn('end_dt', 'datetime', [
                    'notnull' => false,
                ]);
            }
            // Add index for incremental lookup performance
            if (!$table->hasIndex('jca_user_end_idx')) {
                $table->addIndex(['user_id', 'end_dt'], 'jca_user_end_idx');
            }
        }

        return $schema;
    }
}
