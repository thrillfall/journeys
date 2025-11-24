<?php
namespace OCA\Journeys\Service;

use OCP\IDBConnection;

class SimplePlaceResolver {
    private string $prefix;
    private string $planetTable;
    private string $geometryTable;
    private string $placesTable;
    private IDBConnection $db;
    private int $gisType;
    private string $tablePrefix;

    const GIS_TYPE_NONE = 0;
    const GIS_TYPE_MYSQL = 1;
    const GIS_TYPE_POSTGRES = 2;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
        $this->gisType = $this->detectGisType();
        $this->prefix = '';
        $this->tablePrefix = method_exists($db, 'getPrefix') ? (string)$db->getPrefix() : 'oc_';
        // Per Memories documentation, geometry table is unprefixed; planet & places have single prefix
        $this->planetTable = $this->tablePrefix . 'memories_planet';
        $this->geometryTable = 'memories_planet_geometry';
        $this->placesTable = $this->tablePrefix . 'memories_places';
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
    public function queryPoint(float $lat, float $lon, ?int $fileId = null): array {
        if ($this->gisType === self::GIS_TYPE_NONE) {
            return $this->fallbackByFileId($fileId);
        }
        if ($this->gisType === self::GIS_TYPE_MYSQL) {
            $where = "ST_Contains(geometry, ST_GeomFromText('POINT($lat $lon)', 4326))";
        } elseif ($this->gisType === self::GIS_TYPE_POSTGRES) {
            $where = "geometry && ST_SetSRID(ST_MakePoint($lat, $lon), 4326) AND ST_Contains(geometry, ST_SetSRID(ST_MakePoint($lat, $lon), 4326))";
        } else {
            return $this->fallbackByFileId($fileId);
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
            return $this->fallbackByFileId($fileId);
        }
    }

    private function fallbackByFileId(?int $fileId): array {
        if ($fileId === null) {
            return [];
        }
        try {
            $sql = "
                SELECT mp.osm_id, mp.admin_level, mp.name
                FROM {$this->placesTable} p
                INNER JOIN {$this->planetTable} mp ON p.osm_id = mp.osm_id
                WHERE p.fileid = ?
                ORDER BY mp.admin_level ASC
            ";
            return $this->db->executeQuery($sql, [$fileId])->fetchAll();
        } catch (\Throwable $e) {
            error_log('[SimplePlaceResolver] Fallback DB error for fileid=' . $fileId . ': ' . $e->getMessage());
            return [];
        }
    }
}
