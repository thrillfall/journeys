<?php
namespace OCA\Journeys\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Year-in-review videos are not rendered from an album: album_id becomes
 * nullable and review_year names the year instead.
 */
class Version0700Date20261118 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        if (!$schema->hasTable('journeys_videos')) {
            return null;
        }
        $table = $schema->getTable('journeys_videos');
        $changed = false;
        if ($table->getColumn('album_id')->getNotnull()) {
            $table->getColumn('album_id')->setNotnull(false);
            $changed = true;
        }
        if (!$table->hasColumn('review_year')) {
            $table->addColumn('review_year', Types::INTEGER, ['notnull' => false, 'default' => null]);
            $changed = true;
        }
        return $changed ? $schema : null;
    }
}
