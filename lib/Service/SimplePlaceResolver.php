<?php
namespace OCA\Journeys\Service;

use OCP\IDBConnection;

class SimplePlaceResolver {
    private string $prefix;
    private string $planetTable;
    private string $geometryTable;
    private IDBConnection $db;
    private int $gisType;

    const GIS_TYPE_NONE = 0;
    const GIS_TYPE_MYSQL = 1;
    const GIS_TYPE_POSTGRES = 2;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
        $this->gisType = $this->detectGisType();
        // Fetch prefix from config
        $this->prefix = '';
        if (class_exists('OC') && method_exists('OC', 'getServer')) {
            $config = \OC::$server->getConfig();
        }
        // Per Memories documentation, these tables are always unprefixed, regardless of Nextcloud dbtableprefix
        // Table names based on actual DB schema: geometry is unprefixed, planet (name/admin_level) is prefixed
        $this->planetTable = 'oc_' . 'memories_planet';
        $this->geometryTable = 'memories_planet_geometry';
    }

    private function detectGisType(): int {
        $platform = $this->db->getDatabasePlatform();
        $class = get_class($platform);
        if (stripos($class, 'mysql') !== false || stripos($class, 'mariadb') !== false) {
            return self::GIS_TYPE_MYSQL;
        } elseif (stripos($class, 'postgres') !== false) {
            return self::GIS_TYPE_POSTGRES;
        }
        return self::GIS_TYPE_NONE;
    }

    /**
     * Returns an array of places (osm_id, admin_level, name) for a given point.
     */
    public function queryPoint(float $lat, float $lon): array {
        if ($this->gisType === self::GIS_TYPE_MYSQL) {
            $where = "ST_Contains(geometry, ST_GeomFromText('POINT($lat $lon)', 4326))";
        } elseif ($this->gisType === self::GIS_TYPE_POSTGRES) {
            $where = "geometry && ST_SetSRID(ST_MakePoint($lat, $lon), 4326) AND ST_Contains(geometry, ST_SetSRID(ST_MakePoint($lat, $lon), 4326))";
        } else {
            return [];
        }
        $sql = "
            SELECT mp.osm_id, mp.admin_level, mp.name
            FROM {$this->geometryTable} g
            INNER JOIN {$this->planetTable} mp ON g.osm_id = mp.osm_id
            WHERE $where
            ORDER BY mp.admin_level ASC
        ";
        try {
            return $this->db->executeQuery($sql)->fetchAll();
        } catch (\Throwable $e) {
            error_log('[SimplePlaceResolver] DB error for lat=' . $lat . ', lon=' . $lon . ": " . $e->getMessage() . "\nSQL: $sql");
            return [];
        }
    }
}
