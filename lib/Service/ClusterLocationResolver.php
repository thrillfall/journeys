<?php
namespace OCA\Journeys\Service;

use OCA\Journeys\Service\SimplePlaceResolver;
use OCA\Journeys\Model\Image;
use OCP\IDBConnection;

class ClusterLocationResolver {
    private SimplePlaceResolver $placeResolver;
    private IDBConnection $db;
    private string $planetTable;

    public function __construct(SimplePlaceResolver $placeResolver, IDBConnection $db) {
        $this->db = $db;
        $this->placeResolver = $placeResolver;
        // Per Memories documentation, these tables are always unprefixed, regardless of Nextcloud dbtableprefix
        // Table names based on actual DB schema: geometry is unprefixed, planet (name/admin_level) is prefixed
        $this->planetTable = 'memories_planet';
    }

    /**
     * Resolve the best location name for a cluster of images.
     *
     * @param Image[] $images
     * @return string|null Location name (city, region, or country)
     */
    /**
     * @param Image[] $images
     * @param bool $preferBroaderLevel If true, prefer a higher (less detailed) admin_level for the location name
     */
    public function resolveClusterLocation(array $images, bool $preferBroaderLevel = true): ?string {
        $locations = [];
        foreach ($images as $img) {
            if ($img->lat !== null && $img->lon !== null) {
                $places = $this->placeResolver->queryPoint($img->lat, $img->lon);
                if (!empty($places)) {
                    // Sort by admin_level ascending (more specific first)
                    usort($places, function($a, $b) {
                        return $a['admin_level'] <=> $b['admin_level'];
                    });
                    foreach ($places as $place) {
                        $locations[] = [
                            'osm_id' => $place['osm_id'],
                            'admin_level' => $place['admin_level'],
                            'name' => $this->getPlaceName($place['osm_id'])
                        ];
                    }
                }
            }
        }
        if (empty($locations)) {
            return null;
        }
        // Group by name and admin_level
        $byName = [];
        foreach ($locations as $loc) {
            $key = $loc['name'] . '|' . $loc['admin_level'];
            if (!isset($byName[$key])) {
                $byName[$key] = 0;
            }
            $byName[$key]++;
        }
        // Sort by admin_level descending (broader first)
        uksort($byName, function($a, $b) {
            $levelA = (int)explode('|', $a)[1];
            $levelB = (int)explode('|', $b)[1];
            return $levelB <=> $levelA;
        });
        if ($preferBroaderLevel) {
            // Try to find the broadest level shared by most images
            foreach ($byName as $key => $count) {
                if ($count >= count($images) / 2) { // majority
                    return explode('|', $key)[0];
                }
            }
            // Fallback: pick the broadest available
            $broadestKey = array_key_first($byName);
            return explode('|', $broadestKey)[0];
        } else {
            // Original behavior (most common, possibly more specific)
            arsort($byName);
            $mostCommon = array_key_first($byName);
            return explode('|', $mostCommon)[0];
        }
    }

    /**
     * Get the place name for a given OSM ID (city, region, country, etc.)
     *
     * @param int $osmId
     * @return string|null
     */
    private function getPlaceName(int $osmId): ?string {
        $query = $this->db->getQueryBuilder();
        $query->select('name')
            ->from($this->planetTable)
            ->where($query->expr()->eq('osm_id', $query->createNamedParameter($osmId)));
        $row = $query->executeQuery()->fetch();
        return $row ? $row['name'] : null;
    }
}
