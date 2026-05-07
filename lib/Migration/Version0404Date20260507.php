<?php
namespace OCA\Journeys\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version0404Date20260507 extends SimpleMigrationStep {
    /**
     * Add nullable custom_name column to journeys_cluster_albums for user-set
     * journey display names that survive re-clustering.
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $tableName = 'journeys_cluster_albums';
        if ($schema->hasTable($tableName)) {
            $table = $schema->getTable($tableName);
            if (!$table->hasColumn('custom_name')) {
                $table->addColumn('custom_name', 'text', [
                    'notnull' => false,
                    'default' => null,
                ]);
            }
        }

        return $schema;
    }
}
