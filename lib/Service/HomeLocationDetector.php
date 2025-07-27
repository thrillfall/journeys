<?php
namespace OCA\Journeys\Service;

class HomeLocationDetector {
    private $locationResolver;

    public function __construct(ClusterLocationResolver $locationResolver) {
        $this->locationResolver = $locationResolver;
    }

    public function detect(array $images): ?array {
        $locationBuckets = [];
        foreach ($images as $img) {
            if ($img->lat === null || $img->lon === null) continue;
            $lat = round($img->lat, 1);
            $lon = round($img->lon, 1);
            $key = $lat . ',' . $lon;
            if (!isset($locationBuckets[$key])) {
                $locationBuckets[$key] = [ 'count' => 0, 'lat' => $lat, 'lon' => $lon, 'images' => [] ];
            }
            $locationBuckets[$key]['count']++;
            $locationBuckets[$key]['images'][] = $img;
        }
        if (empty($locationBuckets)) return null;
        usort($locationBuckets, function($a, $b) { return $b['count'] <=> $a['count']; });
        $home = $locationBuckets[0];
        $sumLat = 0; $sumLon = 0;
        foreach ($home['images'] as $img) {
            $sumLat += $img->lat;
            $sumLon += $img->lon;
        }
        $n = count($home['images']);
        $centroidLat = $n ? $sumLat / $n : $home['lat'];
        $centroidLon = $n ? $sumLon / $n : $home['lon'];
        $name = null;
        if ($this->locationResolver) {
            $name = $this->locationResolver->resolveClusterLocation($home['images'], true);
        }
        return [ 'lat' => $centroidLat, 'lon' => $centroidLon, 'name' => $name ];
    }
}
