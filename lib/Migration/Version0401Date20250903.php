<?php
namespace OCA\Journeys\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version0401Date20250903 extends SimpleMigrationStep {
	/**
	 * Ensure the journeys_cluster_albums tracking table exists
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
		if (!$schema->hasTable($tableName)) {
			$table = $schema->createTable($tableName);
			$table->addColumn('user_id', 'string', [
				'length' => 64,
				'notnull' => true,
			]);
			$table->addColumn('album_id', 'bigint', [
				'notnull' => true,
			]);
			$table->addColumn('name', 'text', [
				'notnull' => true,
			]);
			$table->addColumn('location', 'text', [
				'notnull' => false,
			]);
			// Use a short explicit index name to avoid name length issues on some DBs
			$table->setPrimaryKey(['user_id', 'album_id'], 'jca_pk');
		}

		return $schema;
	}
}
