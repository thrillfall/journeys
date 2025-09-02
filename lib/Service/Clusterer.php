<?php
namespace OCA\Journeys\Service;

use OCA\Journeys\Model\Image;

class Clusterer {
    /**
     * Threshold-based time & location clustering with time-only fallback for missing locations.
     *
     * @param Image[] $images Sorted by datetaken ascending
     * @param int $maxTimeGap Max allowed time gap in seconds
     * @param float $maxDistanceKm Max allowed distance in kilometers
     * @return Image[][] Array of clusters (each cluster is an array of Image)
     */
    public function clusterImages(array $images, int $maxTimeGap = 86400, float $maxDistanceKm = 100.0): array {
        $clusters = [];
        $currentCluster = [];
        $prev = null;
        foreach ($images as $img) {
            if (empty($currentCluster)) {
                $currentCluster[] = $img;
            } else {
                $timeGap = abs(strtotime($img->datetaken) - strtotime($prev->datetaken));
                $hasLoc1 = $img->lat !== null && $img->lon !== null;
                $hasLoc2 = $prev->lat !== null && $prev->lon !== null;
                if ($hasLoc1 && $hasLoc2) {
                    $dist = $this->haversine($img->lat, $img->lon, $prev->lat, $prev->lon);
                    $shouldSplit = $timeGap > $maxTimeGap || $dist > $maxDistanceKm;
                } else {
                    $shouldSplit = $timeGap > $maxTimeGap;
                }
                if ($shouldSplit) {
                    $clusters[] = $currentCluster;
                    $currentCluster = [];
                }
                $currentCluster[] = $img;
            }
            $prev = $img;
        }
        if (!empty($currentCluster)) {
            $clusters[] = $currentCluster;
        }
        return $clusters;
    }

    /**
     * Haversine distance in kilometers
     */
    private function haversine($lat1, $lon1, $lat2, $lon2): float {
        $R = 6371; // Earth radius in km
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat/2) * sin($dLat/2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon/2) * sin($dLon/2);
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        return $R * $c;
    }

    /**
     * Home-aware clustering with separate thresholds for near-home vs away-from-home.
     * Single-home model. If home is null, falls back to regular clustering.
     *
     * @param Image[] $images Sorted by datetaken ascending
     * @param array|null $home ['lat' => float, 'lon' => float, 'radiusKm' => float]
     * @param array $thresholds ['near' => ['timeGap' => int, 'distanceKm' => float], 'away' => ['timeGap' => int, 'distanceKm' => float]]
     * @return Image[][]
     */
    public function clusterImagesHomeAware(array $images, ?array $home, array $thresholds): array {
        if ($home === null || !isset($home['lat'], $home['lon'], $home['radiusKm'])) {
            // No home information; default to standard clustering using away thresholds
            $t = $thresholds['away'] ?? ['timeGap' => 86400, 'distanceKm' => 100.0];
            return $this->clusterImages($images, (int)$t['timeGap'], (float)$t['distanceKm']);
        }

        // Segment the timeline by proximity to home (near vs away), then cluster each segment
        if (empty($images)) {
            return [];
        }

        // Build near/away flags per image
        $flags = [];
        foreach ($images as $idx => $img) {
            $isNear = true; // default to near if no coords for first
            if ($img->lat !== null && $img->lon !== null) {
                $isNear = ($this->haversine($img->lat, $img->lon, $home['lat'], $home['lon']) <= (float)$home['radiusKm']);
            } elseif ($idx > 0) {
                // inherit previous when missing coords
                $isNear = $flags[$idx - 1];
            }
            $flags[$idx] = $isNear;
        }

        // Split into contiguous segments with same flag
        $segments = [];
        $segStart = 0;
        for ($i = 1; $i < count($images); $i++) {
            if ($flags[$i] !== $flags[$i - 1]) {
                $segments[] = [ 'start' => $segStart, 'end' => $i - 1, 'near' => $flags[$i - 1] ];
                $segStart = $i;
            }
        }
        $segments[] = [ 'start' => $segStart, 'end' => count($images) - 1, 'near' => $flags[count($images) - 1] ];

        $allClusters = [];
        foreach ($segments as $seg) {
            $slice = array_slice($images, $seg['start'], $seg['end'] - $seg['start'] + 1);
            $t = $seg['near']
                ? ($thresholds['near'] ?? ['timeGap' => 21600, 'distanceKm' => 3.0])
                : ($thresholds['away'] ?? ['timeGap' => 129600, 'distanceKm' => 50.0]);
            $clusters = $this->clusterImages($slice, (int)$t['timeGap'], (float)$t['distanceKm']);
            foreach ($clusters as $c) {
                $allClusters[] = $c;
            }
        }
        return $allClusters;
    }
}
