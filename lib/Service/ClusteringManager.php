<?php
namespace OCA\Journeys\Service;

use OCA\Journeys\Model\Image;
use OCA\Journeys\Service\HomeLocationDetector;

class ClusteringManager {

    /**
     * Delegates home location detection to HomeLocationDetector.
     * @param Image[] $images
     * @return array|null
     */
    public function detectHomeLocation(array $images): ?array {
        return $this->homeLocationDetector->detect($images);
    }

    private $imageFetcher;
    private $clusterer;
    private $albumCreator;
    private $locationResolver;
    private $homeLocationDetector;

    public function __construct(ImageFetcher $imageFetcher, Clusterer $clusterer, AlbumCreator $albumCreator, ClusterLocationResolver $locationResolver, HomeLocationDetector $homeLocationDetector) {
        $this->imageFetcher = $imageFetcher;
        $this->clusterer = $clusterer;
        $this->albumCreator = $albumCreator;
        $this->locationResolver = $locationResolver;
        $this->homeLocationDetector = $homeLocationDetector;
    }

    /**
     * Orchestrate fetching, clustering, and album creation for a user.
     * @param string $userId
     * @return array [clustersCreated => int, lastRun => string, error? => string]
     */
    public function clusterForUser(string $userId, int $maxTimeGap = 86400, float $maxDistanceKm = 100.0, int $minClusterSize = 3, bool $homeAware = false, ?array $home = null, ?array $thresholds = null, bool $fromScratch = false, int $recentCutoffDays = 5): array {
        // Purge behavior depends on mode: from-scratch purges, incremental preserves existing albums
        $purgedAlbums = 0;
        if ($fromScratch) {
            $purgedAlbums = $this->albumCreator->purgeClusterAlbums($userId);
        }
        $images = $this->imageFetcher->fetchImagesForUser($userId);
        if (empty($images)) {
            return [
                'error' => 'No images found for user',
                'lastRun' => date('c'),
                'clustersCreated' => 0
            ];
        }
        usort($images, function($a, $b) {
            return strtotime($a->datetaken) <=> strtotime($b->datetaken);
        });
        // Incremental: only consider images after the latest tracked cluster end
        if (!$fromScratch) {
            $latestEnd = $this->albumCreator->getLatestClusterEnd($userId);
            if ($latestEnd !== null) {
                $cutTs = $latestEnd->getTimestamp();
                $images = array_values(array_filter($images, function($img) use ($cutTs) {
                    return strtotime($img->datetaken) > $cutTs;
                }));
            }
            if (empty($images)) {
                return [
                    'clustersCreated' => 0,
                    'lastRun' => date('c'),
                    'clusters' => [],
                    'purgedAlbums' => $purgedAlbums,
                ];
            }
        }
        // Interpolate missing locations (match CLI default: 6h)
        $images = \OCA\Journeys\Service\ImageLocationInterpolator::interpolate($images, 21600);
        if ($homeAware) {
            // Determine home and thresholds
            if ($home === null) {
                $detected = $this->homeLocationDetector->detect($images);
                if ($detected) {
                    $home = [
                        'lat' => $detected['lat'],
                        'lon' => $detected['lon'],
                        'radiusKm' => 50.0,
                        'name' => $detected['name'] ?? null,
                    ];
                } else {
                    // Fallback: disable home-aware if we cannot detect a home
                    $homeAware = false;
                }
            } else {
                // Ensure radius default
                if (!isset($home['radiusKm'])) {
                    $home['radiusKm'] = 50.0;
                }
            }
        }

        if ($homeAware) {
            $thresholds = $thresholds ?? [
                'near' => ['timeGap' => 21600, 'distanceKm' => 3.0],   // 6h, 3km
                'away' => ['timeGap' => 129600, 'distanceKm' => 50.0], // 36h, 50km
            ];
            // If 'near' thresholds are still at built-in defaults, align them with the non-home-aware thresholds
            if (
                isset($thresholds['near']['timeGap'], $thresholds['near']['distanceKm']) &&
                (int)$thresholds['near']['timeGap'] === 21600 && (float)$thresholds['near']['distanceKm'] === 3.0
            ) {
                $thresholds['near']['timeGap'] = $maxTimeGap;
                // Cap near-home distance to 25km for finer local clustering
                $thresholds['near']['distanceKm'] = min((float)$maxDistanceKm, 25.0);
            }
            $clusters = $this->clusterer->clusterImagesHomeAware($images, $home, $thresholds);
        } else {
            $clusters = $this->clusterer->clusterImages($images, $maxTimeGap, $maxDistanceKm);
        }
        $created = 0;
        $clusterSummaries = [];
        foreach ($clusters as $i => $cluster) {
            if (count($cluster) < $minClusterSize) {
                continue;
            }
            // Discard clusters composed entirely of images without location
            $hasGeolocated = false;
            foreach ($cluster as $img) {
                if ($img->lat !== null && $img->lon !== null) {
                    $hasGeolocated = true;
                    break;
                }
            }
            if (!$hasGeolocated) {
                continue;
            }
            $start = $cluster[0]->datetaken;
            $dtStart = new \DateTime($cluster[0]->datetaken);
            $dtEnd = new \DateTime($cluster[count($cluster)-1]->datetaken);
            // Discard clusters whose last image is considered too recent (incomplete travel)
            if ($recentCutoffDays > 0) {
                $now = new \DateTime('now');
                $ageSeconds = $now->getTimestamp() - $dtEnd->getTimestamp();
                if ($ageSeconds < $recentCutoffDays * 24 * 3600) {
                    continue;
                }
            }
            $monthYear = $dtStart->format('F Y');
            $range = $dtStart->format('M j');
            if ($dtStart->format('Y-m-d') !== $dtEnd->format('Y-m-d')) {
                $range .= '–' . $dtEnd->format('M j');
            }
            $location = $this->locationResolver->resolveClusterLocation($cluster, true);
            if ($location) {
                $albumName = sprintf('%s %s (%s)', $location, $monthYear, $range);
            } else {
                $albumName = sprintf('Journey %d %s (%s)', $i+1, $monthYear, $range);
            }
            $this->albumCreator->createAlbumWithImages($userId, $albumName, $cluster, $location ?? '', $dtStart, $dtEnd);
            $clusterSummaries[] = [
                'albumName' => $albumName,
                'imageCount' => count($cluster),
                'location' => $location
            ];
            $created++;
        }
        return [
            'clustersCreated' => $created,
            'lastRun' => date('c'),
            'clusters' => $clusterSummaries,
            'purgedAlbums' => $purgedAlbums
        ];
    }
    
}
