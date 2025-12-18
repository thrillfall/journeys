<?php
namespace OCA\Journeys\Service;

use OCA\Journeys\Model\Image;
use Psr\Log\LoggerInterface;

class Clusterer {
    private $logger;

    public function __construct(?LoggerInterface $logger = null) {
        $this->logger = $logger;
    }
    /**
     * Threshold-based time & location clustering with time-only fallback for missing locations.
     *
     * @param Image[] $images Sorted by datetaken ascending
     * @param int $maxTimeGap Max allowed time gap in seconds
     * @param float $maxDistanceKm Max allowed distance in kilometers
     * @param callable|null $splitDebug Optional callback invoked when a split happens
     * @return Image[][] Array of clusters (each cluster is an array of Image)
     */
    public function clusterImages(array $images, int $maxTimeGap = 86400, float $maxDistanceKm = 100.0, ?callable $splitDebug = null): array {
        $clusters = [];
        $currentCluster = [];
        $prev = null;
        // Anchor for spatial continuity: last image in the current cluster that has valid coordinates
        $prevGeo = null;
        $dist = null;
        foreach ($images as $img) {
            if (empty($currentCluster)) {
                $currentCluster[] = $img;
                // initialize geo anchor when the first item of the cluster has coords
                if ($img->lat !== null && $img->lon !== null) {
                    $prevGeo = $img;
                } else {
                    $prevGeo = null;
                }
            } else {
                $timeGap = abs(strtotime($img->datetaken) - strtotime($prev->datetaken));
                $hasLocCurrent = $img->lat !== null && $img->lon !== null;

                // Start with time gap rule
                $shouldSplit = $timeGap > $maxTimeGap;
                $reason = null;

                // Spatial rule: compare current geolocated point to the last-known geolocated anchor in the cluster.
                // This prevents a run of unlocated images from "bridging" to a far-away point without splitting.
                if (!$shouldSplit && $hasLocCurrent && $prevGeo !== null) {
                    $dist = $this->haversine($img->lat, $img->lon, $prevGeo->lat, $prevGeo->lon);
                    if ($dist > $maxDistanceKm) {
                        $shouldSplit = true;
                        $reason = 'distance_exceeded';
                    }
                }
                if ($reason === null && $shouldSplit) {
                    $reason = 'time_gap_exceeded';
                }

                if ($shouldSplit) {
                    if ($splitDebug !== null) {
                        try {
                            $clusterIndexBefore = count($clusters);
                            $payload = [
                                'type' => 'split',
                                'reason' => $reason,
                                // Boundary: this split ends raw cluster N and begins raw cluster N+1 (0-based indices)
                                'cluster_index_before' => $clusterIndexBefore,
                                'cluster_index_after' => $clusterIndexBefore + 1,
                                'time_gap_seconds' => $timeGap,
                                'max_time_gap_seconds' => $maxTimeGap,
                                'time_exceeded_by_seconds' => $timeGap > $maxTimeGap ? ($timeGap - $maxTimeGap) : 0,
                                'prev' => $prev instanceof Image ? [
                                    'fileid' => $prev->fileid,
                                    'path' => $prev->path,
                                    'datetaken' => $prev->datetaken,
                                    'datetaken_ts' => strtotime($prev->datetaken) !== false ? (int)strtotime($prev->datetaken) : null,
                                    'lat' => $prev->lat,
                                    'lon' => $prev->lon,
                                ] : null,
                                'curr' => [
                                    'fileid' => $img->fileid,
                                    'path' => $img->path,
                                    'datetaken' => $img->datetaken,
                                    'datetaken_ts' => strtotime($img->datetaken) !== false ? (int)strtotime($img->datetaken) : null,
                                    'lat' => $img->lat,
                                    'lon' => $img->lon,
                                ],
                            ];
                            if ($reason === 'distance_exceeded') {
                                $payload['distance_km'] = $dist;
                                $payload['max_distance_km'] = $maxDistanceKm;
                                $payload['distance_exceeded_by_km'] = $dist !== null ? max(0.0, (float)$dist - (float)$maxDistanceKm) : null;
                                $payload['prev_geo'] = $prevGeo instanceof Image ? [
                                    'fileid' => $prevGeo->fileid,
                                    'path' => $prevGeo->path,
                                    'datetaken' => $prevGeo->datetaken,
                                    'datetaken_ts' => strtotime($prevGeo->datetaken) !== false ? (int)strtotime($prevGeo->datetaken) : null,
                                    'lat' => $prevGeo->lat,
                                    'lon' => $prevGeo->lon,
                                ] : null;
                            }
                            $splitDebug($payload);
                        } catch (\Throwable $e) {
                        }
                    }
                    if ($this->logger !== null) {
                        try {
                            $context = [
                                'reason' => $reason,
                                'prev_datetaken' => $prev ? $prev->datetaken : null,
                                'curr_datetaken' => $img->datetaken,
                                'time_gap_seconds' => $timeGap,
                                'max_time_gap_seconds' => $maxTimeGap,
                            ];
                            if ($reason === 'distance_exceeded') {
                                $context['distance_km'] = $dist;
                                $context['max_distance_km'] = $maxDistanceKm;
                                $context['prev_geo'] = $prevGeo ? ['lat' => $prevGeo->lat, 'lon' => $prevGeo->lon] : null;
                                $context['curr_geo'] = ['lat' => $img->lat, 'lon' => $img->lon];
                            }
                            $this->logger->debug('Journeys clustering: cluster ended', $context);
                        } catch (\Throwable $e) {
                            // ignore logging failures
                        }
                    }
                    $clusters[] = $currentCluster;
                    $currentCluster = [];
                    // Reset geo anchor for new cluster
                    $prevGeo = null;
                }
                $currentCluster[] = $img;
                // Update geo anchor only when the current image has coordinates
                if ($img->lat !== null && $img->lon !== null) {
                    $prevGeo = $img;
                }
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
     * @param callable|null $splitDebug Optional callback invoked when a split/boundary happens
     * @return Image[][]
     */
    public function clusterImagesHomeAware(array $images, ?array $home, array $thresholds, ?callable $splitDebug = null): array {
        if ($home === null || !isset($home['lat'], $home['lon'], $home['radiusKm'])) {
            // No home information; default to standard clustering using away thresholds
            $t = $thresholds['away'] ?? ['timeGap' => 86400, 'distanceKm' => 100.0];
            return $this->clusterImages($images, (int)$t['timeGap'], (float)$t['distanceKm'], $splitDebug);
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
                if ($splitDebug !== null) {
                    try {
                        $prevImg = $images[$i - 1];
                        $currImg = $images[$i];
                        $prevHomeDist = ($prevImg->lat !== null && $prevImg->lon !== null)
                            ? $this->haversine($prevImg->lat, $prevImg->lon, $home['lat'], $home['lon'])
                            : null;
                        $currHomeDist = ($currImg->lat !== null && $currImg->lon !== null)
                            ? $this->haversine($currImg->lat, $currImg->lon, $home['lat'], $home['lon'])
                            : null;
                        $splitDebug([
                            'type' => 'home_boundary',
                            'index_prev' => $i - 1,
                            'index_curr' => $i,
                            'prev_near' => $flags[$i - 1],
                            'curr_near' => $flags[$i],
                            'home' => ['lat' => $home['lat'], 'lon' => $home['lon'], 'radiusKm' => $home['radiusKm']],
                            'prev' => [
                                'fileid' => $prevImg->fileid,
                                'path' => $prevImg->path,
                                'datetaken' => $prevImg->datetaken,
                                'datetaken_ts' => strtotime($prevImg->datetaken) !== false ? (int)strtotime($prevImg->datetaken) : null,
                                'lat' => $prevImg->lat,
                                'lon' => $prevImg->lon,
                                'home_distance_km' => $prevHomeDist,
                            ],
                            'curr' => [
                                'fileid' => $currImg->fileid,
                                'path' => $currImg->path,
                                'datetaken' => $currImg->datetaken,
                                'datetaken_ts' => strtotime($currImg->datetaken) !== false ? (int)strtotime($currImg->datetaken) : null,
                                'lat' => $currImg->lat,
                                'lon' => $currImg->lon,
                                'home_distance_km' => $currHomeDist,
                            ],
                        ]);
                    } catch (\Throwable $e) {
                    }
                }
                // Log boundary caused by near/away change
                if ($this->logger !== null) {
                    try {
                        $this->logger->debug('Journeys clustering: near/away boundary', [
                            'index_prev' => $i - 1,
                            'index_curr' => $i,
                            'prev_datetaken' => $images[$i - 1]->datetaken,
                            'curr_datetaken' => $images[$i]->datetaken,
                            'prev_near' => $flags[$i - 1],
                            'curr_near' => $flags[$i],
                            'home' => ['lat' => $home['lat'], 'lon' => $home['lon'], 'radiusKm' => $home['radiusKm']],
                        ]);
                    } catch (\Throwable $e) {}
                }
                $segments[] = [ 'start' => $segStart, 'end' => $i - 1, 'near' => $flags[$i - 1] ];
                $segStart = $i;
            }
        }
        $segments[] = [ 'start' => $segStart, 'end' => count($images) - 1, 'near' => $flags[count($images) - 1] ];

        $allClusters = [];
        $globalOffset = 0;
        foreach ($segments as $seg) {
            $slice = array_slice($images, $seg['start'], $seg['end'] - $seg['start'] + 1);
            $t = $seg['near']
                ? ($thresholds['near'] ?? ['timeGap' => 21600, 'distanceKm' => 3.0])
                : ($thresholds['away'] ?? ['timeGap' => 129600, 'distanceKm' => 50.0]);
            if ($this->logger !== null) {
                try {
                    $this->logger->debug('Journeys clustering: segment thresholds', [
                        'segment' => $seg,
                        'timeGap' => (int)$t['timeGap'],
                        'distanceKm' => (float)$t['distanceKm'],
                    ]);
                } catch (\Throwable $e) {}
            }
            $wrappedSplitDebug = null;
            if ($splitDebug !== null) {
                $wrappedSplitDebug = function(array $ev) use ($splitDebug, $globalOffset, $seg) {
                    // Re-map local cluster boundary indices (within the segment) to global indices.
                    if (isset($ev['cluster_index_before'])) {
                        $ev['cluster_index_before_global'] = $globalOffset + (int)$ev['cluster_index_before'];
                    }
                    if (isset($ev['cluster_index_after'])) {
                        $ev['cluster_index_after_global'] = $globalOffset + (int)$ev['cluster_index_after'];
                    }
                    $ev['segment'] = $seg;
                    $splitDebug($ev);
                };
            }
            $clusters = $this->clusterImages($slice, (int)$t['timeGap'], (float)$t['distanceKm'], $wrappedSplitDebug);
            foreach ($clusters as $c) {
                $allClusters[] = $c;
            }
            $globalOffset += count($clusters);
        }
        return $allClusters;
    }
}
