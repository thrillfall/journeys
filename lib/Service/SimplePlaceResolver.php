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
    private bool $loggedQueryError = false;
    private bool $loggedFallbackError = false;

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
            return $this->hasMysqlGis() ? self::GIS_TYPE_MYSQL : self::GIS_TYPE_NONE;
        } elseif (stripos($class, 'postgres') !== false) {
            // No PostGIS probe: see queryPoint(). PostGIS is irrelevant to this
            // table, so its presence or absence decides nothing.
            return self::GIS_TYPE_POSTGRES;
        }
        return self::GIS_TYPE_NONE;
    }

    /**
     * Whether MySQL/MariaDB spatial functions are usable. Built-in since
     * MySQL 5.7 / MariaDB 10.2, but probe rather than assume so a stripped
     * build also falls back cleanly instead of erroring per point.
     */
    private function hasMysqlGis(): bool {
        try {
            $this->db->executeQuery(
                "SELECT ST_Contains(ST_GeomFromText('POLYGON((0 0,0 1,1 1,1 0,0 0))'), ST_GeomFromText('POINT(0.5 0.5)'))"
            )->fetchOne();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Returns an array of places (osm_id, admin_level, name) for a given point.
     *
     * On PostgreSQL this must NOT use PostGIS. Memories stores
     * memories_planet_geometry.geometry as Postgres's built-in `polygon` type
     * and never links against PostGIS at all, so ST_MakePoint/ST_Contains do
     * not merely require an extension - they are the wrong types for the
     * column. Calling them threw once per photo per cron run: 183,700 log
     * lines in a day here, and the place lookup silently degraded to the
     * per-file fallback for every PostgreSQL user.
     *
     * The built-in operators do the same job and are indexable. The GiST index
     * Memories creates on that column uses the `poly_ops` operator class,
     * which covers polygon-vs-polygon `<@` but not point-vs-polygon - hence
     * the degenerate single-vertex polygon built from the point, which is the
     * same construction Memories itself uses in its own Places service.
     *
     * Measured on a 660k-row planet table (Nextcloud 34, PostgreSQL 17):
     * Bitmap Index Scan, 10 candidate rows, 1.7 ms - against a 437 ms parallel
     * sequential scan for the non-indexable point form.
     */
    public function queryPoint(float $lat, float $lon, ?int $fileId = null): array {
        if ($this->gisType === self::GIS_TYPE_NONE) {
            return $this->fallbackByFileId($fileId);
        }
        if ($this->gisType === self::GIS_TYPE_MYSQL) {
            $where = "ST_Contains(geometry, ST_GeomFromText('POINT($lat $lon)', 4326))";
        } elseif ($this->gisType === self::GIS_TYPE_POSTGRES) {
            // %.8F, not interpolation: a float rendered under a locale that
            // uses a decimal comma would produce POLYGON('55,67,12,56').
            $point = sprintf('%.8F,%.8F', $lat, $lon);
            $where = "POLYGON('{$point}') <@ g.geometry";
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
            // Once per request, not once per point. This is called for every
            // photo of every cluster, so a condition that fails for one point
            // fails for all of them - logging each occurrence turned a single
            // broken query into six figures of identical log lines.
            if (!$this->loggedQueryError) {
                $this->loggedQueryError = true;
                error_log('[SimplePlaceResolver] DB error for lat=' . $lat . ', lon=' . $lon . ': '
                    . $e->getMessage() . ' (further occurrences suppressed)' . "\nSQL: $sql");
            }
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
            if (!$this->loggedFallbackError) {
                $this->loggedFallbackError = true;
                error_log('[SimplePlaceResolver] Fallback DB error for fileid=' . $fileId . ': '
                    . $e->getMessage() . ' (further occurrences suppressed)');
            }
            return [];
        }
    }
}
