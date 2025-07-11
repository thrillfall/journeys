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
}
