<?php
namespace OCA\Journeys\Service;

use OCA\Journeys\Model\Image;
use Psr\Log\LoggerInterface;

/**
 * Post-clustering merge pass: stitches adjacent clusters that the raw distance/time
 * splits incorrectly broke apart, when they look like the same journey.
 *
 * Runs AFTER Clusterer (home-aware or not) and BEFORE album creation. Does not
 * modify the clusterer. Pure second-pass logic, easy to disable via --no-merge
 * or the mergeAdjacent user setting.
 *
 * Two adjacent clusters A, B merge when ALL hold:
 *   1. Both have at least one geolocated image.
 *   2. When home is known: both are "away from home" (majority of geolocated
 *      images outside home radius). Near-home clusters and near↔away pairs
 *      never merge — re-entering home has always ended a journey here.
 *   3. Time gap between last-geolocated of A and first-geolocated of B is
 *      <= maxMergeGapDays (default 7).
 *   4. Same country (resolved via $resolveCountry callback, typically
 *      ClusterLocationResolver at admin_level=2).
 *
 * Note on velocity / distance thresholds: deliberately not used. "Plausible
 * velocity" is not a positive signal — a Paris→Tokyo flight 2 days apart has
 * velocity ~200 km/h (low, "plausible") but is obviously not the same trip.
 * Low velocity is the default for photos days apart; it tells us nothing.
 * If Places data is unavailable and country can't be resolved, prefer to leave
 * clusters split rather than guess.
 *
 * Runs to fixpoint: merging A+B may unlock merging (A+B)+C.
 *
 * Noise tolerance: when the direct A+B check fails (typically on country mismatch)
 * and B is smaller than $minClusterSize, we try A+C. If A and C satisfy the merge
 * rules, we absorb A+B+C into a single cluster. This handles tiny GPS-noise
 * clusters (e.g. single photos with a device-default coordinate) sitting between
 * two legs of the same trip. B gets absorbed rather than dropped — it's part of
 * the timeline, just geographically nonsense.
 */
class ClusterMerger {
    public const MAX_MERGE_GAP_DAYS = 7;

    public function __construct(
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * @param Image[][] $clusters Chronological clusters from Clusterer
     * @param array|null $home ['lat', 'lon', 'radiusKm'] or null to skip home-state check
     * @param callable|null $resolveCountry fn(Image[]): ?string — required; null disables merging entirely
     * @param callable|null $mergeDebug fn(array): void — one event per merge/no-merge decision
     * @param int $maxMergeGapDays
     * @param int $minClusterSize Clusters strictly smaller than this are treated as "noise"
     *                            and may be absorbed into A+C merges. Default 1 disables this.
     * @return Image[][]
     */
    public function mergeAdjacent(
        array $clusters,
        ?array $home = null,
        ?callable $resolveCountry = null,
        ?callable $mergeDebug = null,
        int $maxMergeGapDays = self::MAX_MERGE_GAP_DAYS,
        int $minClusterSize = 1,
    ): array {
        if (count($clusters) < 2 || $resolveCountry === null) {
            return $clusters;
        }

        $maxGapSec = $maxMergeGapDays * 86400;
        $changed = true;
        while ($changed) {
            $changed = false;
            $out = [];
            $i = 0;
            $n = count($clusters);
            while ($i < $n) {
                if ($i + 1 >= $n) {
                    $out[] = $clusters[$i];
                    break;
                }
                $decision = $this->shouldMerge(
                    $clusters[$i],
                    $clusters[$i + 1],
                    $home,
                    $resolveCountry,
                    $maxGapSec,
                );
                if ($decision['merge']) {
                    $this->emitMerge($decision['payload'], $mergeDebug);
                    $out[] = array_merge($clusters[$i], $clusters[$i + 1]);
                    $i += 2;
                    $changed = true;
                    continue;
                }

                // Look-past-noise: if B is tiny and A+C would merge, absorb B.
                if (
                    $i + 2 < $n
                    && count($clusters[$i + 1]) < $minClusterSize
                    && $this->isCountryRelatedNoMerge($decision)
                ) {
                    $acDecision = $this->shouldMerge(
                        $clusters[$i],
                        $clusters[$i + 2],
                        $home,
                        $resolveCountry,
                        $maxGapSec,
                    );
                    if ($acDecision['merge']) {
                        $payload = $acDecision['payload'];
                        $payload['reason'] = 'same_country_through_noise';
                        $payload['noise_size'] = count($clusters[$i + 1]);
                        $noiseFirst = $this->firstGeolocated($clusters[$i + 1]);
                        if ($noiseFirst !== null) {
                            $payload['noise_start'] = [
                                'fileid' => $noiseFirst->fileid,
                                'datetaken' => $noiseFirst->datetaken,
                                'lat' => $noiseFirst->lat,
                                'lon' => $noiseFirst->lon,
                            ];
                        }
                        $this->emitMerge($payload, $mergeDebug);
                        $out[] = array_merge($clusters[$i], $clusters[$i + 1], $clusters[$i + 2]);
                        $i += 3;
                        $changed = true;
                        continue;
                    }
                }

                if (isset($decision['payload']) && $mergeDebug !== null) {
                    try { $mergeDebug($decision['payload']); } catch (\Throwable) {}
                }
                $out[] = $clusters[$i];
                $i++;
            }
            $clusters = $out;
        }
        return $clusters;
    }

    private function emitMerge(array $payload, ?callable $mergeDebug): void {
        if ($mergeDebug !== null) {
            try { $mergeDebug($payload); } catch (\Throwable) {}
        }
        if ($this->logger !== null) {
            try { $this->logger->debug('Journeys clustering: merged adjacent clusters', $payload); } catch (\Throwable) {}
        }
    }

    private function isCountryRelatedNoMerge(array $decision): bool {
        $reason = $decision['payload']['reason'] ?? null;
        return in_array($reason, ['country_mismatch', 'country_null_a', 'country_null_b', 'country_null_both'], true);
    }

    /**
     * @param Image[] $a
     * @param Image[] $b
     * @return array{merge:bool, payload?:array}
     */
    private function shouldMerge(
        array $a,
        array $b,
        ?array $home,
        callable $resolveCountry,
        int $maxGapSec,
    ): array {
        $aLast = $this->lastGeolocated($a);
        $bFirst = $this->firstGeolocated($b);
        if ($aLast === null || $bFirst === null) {
            return ['merge' => false];
        }

        if ($home !== null && isset($home['lat'], $home['lon'], $home['radiusKm'])) {
            if (!$this->clusterIsAwayFromHome($a, $home) || !$this->clusterIsAwayFromHome($b, $home)) {
                return ['merge' => false];
            }
        }

        $tsA = strtotime($aLast->datetaken);
        $tsB = strtotime($bFirst->datetaken);
        if ($tsA === false || $tsB === false || $tsB < $tsA) {
            return ['merge' => false];
        }
        $gapSec = $tsB - $tsA;
        if ($gapSec > $maxGapSec) {
            return ['merge' => false];
        }

        $countryA = $resolveCountry($a);
        $countryB = $resolveCountry($b);
        if ($countryA === null || $countryB === null || $countryA !== $countryB) {
            $reason = $countryA === null && $countryB === null ? 'country_null_both'
                : ($countryA === null ? 'country_null_a'
                : ($countryB === null ? 'country_null_b' : 'country_mismatch'));
            return ['merge' => false, 'payload' => [
                'type' => 'no_merge',
                'reason' => $reason,
                'gap_seconds' => $gapSec,
                'gap_days' => round($gapSec / 86400, 2),
                'distance_km' => round($this->haversine($aLast->lat, $aLast->lon, $bFirst->lat, $bFirst->lon), 2),
                'country_a' => $countryA,
                'country_b' => $countryB,
                'cluster_a_size' => count($a),
                'cluster_b_size' => count($b),
                'a_end' => [
                    'fileid' => $aLast->fileid,
                    'datetaken' => $aLast->datetaken,
                    'lat' => $aLast->lat,
                    'lon' => $aLast->lon,
                ],
                'b_start' => [
                    'fileid' => $bFirst->fileid,
                    'datetaken' => $bFirst->datetaken,
                    'lat' => $bFirst->lat,
                    'lon' => $bFirst->lon,
                ],
            ]];
        }

        $distanceKm = $this->haversine($aLast->lat, $aLast->lon, $bFirst->lat, $bFirst->lon);
        $payload = [
            'type' => 'merge',
            'reason' => 'same_country',
            'gap_seconds' => $gapSec,
            'gap_days' => round($gapSec / 86400, 2),
            'distance_km' => round($distanceKm, 2),
            'country' => $countryA,
            'cluster_a_size' => count($a),
            'cluster_b_size' => count($b),
            'a_end' => [
                'fileid' => $aLast->fileid,
                'datetaken' => $aLast->datetaken,
                'lat' => $aLast->lat,
                'lon' => $aLast->lon,
            ],
            'b_start' => [
                'fileid' => $bFirst->fileid,
                'datetaken' => $bFirst->datetaken,
                'lat' => $bFirst->lat,
                'lon' => $bFirst->lon,
            ],
        ];
        return ['merge' => true, 'payload' => $payload];
    }

    /**
     * @param Image[] $cluster
     */
    private function firstGeolocated(array $cluster): ?Image {
        foreach ($cluster as $img) {
            if ($img->lat !== null && $img->lon !== null) {
                return $img;
            }
        }
        return null;
    }

    /**
     * @param Image[] $cluster
     */
    private function lastGeolocated(array $cluster): ?Image {
        for ($i = count($cluster) - 1; $i >= 0; $i--) {
            if ($cluster[$i]->lat !== null && $cluster[$i]->lon !== null) {
                return $cluster[$i];
            }
        }
        return null;
    }

    /**
     * A cluster is "away" when the majority of its geolocated images are outside the home radius.
     * Clusters produced by clusterImagesHomeAware will be uniformly one or the other, since it
     * pre-segments by home-state. For the non-home-aware path $home is null and this is skipped.
     *
     * @param Image[] $cluster
     * @param array{lat:float,lon:float,radiusKm:float} $home
     */
    private function clusterIsAwayFromHome(array $cluster, array $home): bool {
        $away = 0;
        $near = 0;
        foreach ($cluster as $img) {
            if ($img->lat === null || $img->lon === null) {
                continue;
            }
            $d = $this->haversine($img->lat, $img->lon, (float)$home['lat'], (float)$home['lon']);
            if ($d > (float)$home['radiusKm']) {
                $away++;
            } else {
                $near++;
            }
        }
        return $away > 0 && $away >= $near;
    }

    private function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float {
        $R = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $R * $c;
    }
}
