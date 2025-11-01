<?php
namespace OCA\Journeys\Service;

use OCA\Journeys\Model\Image;
use OCA\Journeys\Service\HomeLocationDetector;
use OCA\Journeys\Service\HomeService;
use OCP\Notification\IManager as NotificationManager;
use OCP\IURLGenerator;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

class ClusteringManager {

    private $imageFetcher;
    private $clusterer;
    private $albumCreator;
    private $locationResolver;
    private $homeLocationDetector;
    private HomeService $homeService;
    private NotificationManager $notificationManager;
    private LoggerInterface $logger;
    private IURLGenerator $urlGenerator;
    private ClusterVideoJobRunner $videoJobRunner;
    private VideoRenderJobScheduler $videoScheduler;
    private IConfig $config;

    public function __construct(ImageFetcher $imageFetcher, Clusterer $clusterer, AlbumCreator $albumCreator, ClusterLocationResolver $locationResolver, HomeLocationDetector $homeLocationDetector, HomeService $homeService, NotificationManager $notificationManager, LoggerInterface $logger, IURLGenerator $urlGenerator, ClusterVideoJobRunner $videoJobRunner, VideoRenderJobScheduler $videoScheduler, IConfig $config) {
        $this->imageFetcher = $imageFetcher;
        $this->clusterer = $clusterer;
        $this->albumCreator = $albumCreator;
        $this->locationResolver = $locationResolver;
        $this->homeLocationDetector = $homeLocationDetector;
        $this->homeService = $homeService;
        $this->notificationManager = $notificationManager;
        $this->logger = $logger;
        $this->urlGenerator = $urlGenerator;
        $this->videoJobRunner = $videoJobRunner;
        $this->videoScheduler = $videoScheduler;
        $this->config = $config;
    }

    /**
     * Orchestrate fetching, clustering, and album creation for a user.
     * @param string $userId
     * @return array [clustersCreated => int, lastRun => string, error? => string]
     */
    public function clusterForUser(string $userId, int $maxTimeGap = 86400, float $maxDistanceKm = 100.0, int $minClusterSize = 3, bool $homeAware = false, ?array $home = null, ?array $thresholds = null, bool $fromScratch = false, int $recentCutoffDays = 2, bool $cronContext = false): array {
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
        // Determine home for home-aware mode before we potentially restrict images for incremental clustering
        $effectiveHomeAware = $homeAware;
        if ($effectiveHomeAware) {
            if ($home === null) {
                // Resolve via HomeService: prefers config, then detects over all images (and stores if detected)
                $resolved = $this->homeService->resolveHome($userId, $images, null, 50.0, true);
                $home = $resolved['home'];
                if ($home === null) {
                    $effectiveHomeAware = false;
                }
            } else {
                // Ensure radius default
                if (!isset($home['radiusKm'])) {
                    $home['radiusKm'] = 50.0;
                }
            }
        }

        // Incremental: only consider images after the latest tracked cluster end
        $isTrulyIncremental = false;
        if (!$fromScratch) {
            $latestEnd = $this->albumCreator->getLatestClusterEnd($userId);
            if ($latestEnd === null && $this->albumCreator->hasTrackedAlbums($userId)) {
                // Fallback: derive latest end from already tracked albums using current images list
                $derived = $this->albumCreator->deriveLatestEndFromTracked($userId, $images);
                if ($derived !== null) {
                    $latestEnd = $derived;
                }
            }
            if ($latestEnd !== null) {
                $cutTs = $latestEnd->getTimestamp();
                $images = array_values(array_filter($images, function($img) use ($cutTs) {
                    return strtotime($img->datetaken) > $cutTs;
                }));
                $isTrulyIncremental = true;
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
        if ($effectiveHomeAware) {
            // Determine home and thresholds
            // At this point $home is either provided, loaded from config, or detected earlier
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
            $albumId = $this->albumCreator->createAlbumWithImages($userId, $albumName, $cluster, $location ?? '', $dtStart, $dtEnd);
            // Auto-generate video for far-away clusters only when triggered by cron
            if ($cronContext && $albumId !== null && $effectiveHomeAware && $home !== null) {
                try {
                    $autoGen = (bool)((int)$this->config->getUserValue($userId, 'journeys', 'autoGenerateVideos', 0));
                    if ($autoGen) {
                        $orientation = $this->config->getUserValue($userId, 'journeys', 'videoOrientation', 'portrait');
                        try {
                            $this->videoScheduler->enqueueIfAway($userId, (int)$albumId, $cluster, $home, $orientation === 'landscape' ? 'landscape' : 'portrait');
                        } catch (\Throwable $e) {
                            // Log but do not interrupt album creation flow
                            try {
                                $this->logger->warning('Journeys: enqueue video render job failed', [
                                    'exception' => $e->getMessage(),
                                ]);
                            } catch (\Throwable $ignored) {}
                        }
                    }
                } catch (\Throwable $e) {
                    // ignore configuration errors
                }
            }
            $clusterSummaries[] = [
                'albumName' => $albumName,
                'imageCount' => count($cluster),
                'location' => $location
            ];
            $created++;
        }
        // Send one aggregated notification for the run if any clusters were created
        if ($created > 0) {
            $this->notifyClusterSummary($userId, $clusterSummaries);
        }
        return [
            'clustersCreated' => $created,
            'lastRun' => date('c'),
            // Latest-first for backend UI
            'clusters' => array_reverse($clusterSummaries),
            'purgedAlbums' => $purgedAlbums
        ];
    }

    // Backward-compatible delegator used by commands
    private function setHomeInConfig(string $userId, array $home): void {
        $this->homeService->setHomeInConfig($userId, $home);
    }

    public function getHomeFromConfig(string $userId): ?array {
        return $this->homeService->getHomeFromConfig($userId);
    }
    
    private function notifyClusterSummary(string $userId, array $clusterSummaries): void {
        try {
            $count = count($clusterSummaries);
            $n = $this->notificationManager->createNotification();
            $n->setApp('journeys')
                ->setUser($userId)
                ->setDateTime(new \DateTime())
                // Identify this run by timestamp
                ->setObject('run', (string)time())
                // Subject key understood by Notifier
                ->setSubject('clusters_summary', [
                    'count' => (string)$count,
                    'first' => $clusterSummaries[0]['albumName'] ?? '',
                    'list' => array_map(fn($c) => $c['albumName'], array_slice($clusterSummaries, 0, 5)),
                ])
                ->setParsedSubject('Journeys: new albums created')
                ->setParsedMessage($this->buildSummaryMessage($clusterSummaries));
            // Add action to open Photos app (albums view)
            try {
                $action = $n->createAction();
                $action->setLabel('Open Photos')
                    ->setLink($this->urlGenerator->getAbsoluteURL('/apps/photos'), 'GET');
                $n->addAction($action);
            } catch (\Throwable $e) {}
            $this->notificationManager->notify($n);
        } catch (\Throwable $e) {
            try {
                $this->logger->warning('Journeys: failed to send summary notification', [
                    'exception' => $e->getMessage(),
                ]);
            } catch (\Throwable $ignored) {}
        }
    }

    private function buildSummaryMessage(array $clusterSummaries): string {
        $count = count($clusterSummaries);
        $names = array_map(fn($c) => $c['albumName'], $clusterSummaries);
        $shown = array_slice($names, 0, 5);
        $msg = sprintf('%d new journey album%s created', $count, $count === 1 ? '' : 's');
        if (!empty($shown)) {
            $msg .= ': ' . implode(', ', $shown);
            if (count($names) > count($shown)) {
                $msg .= sprintf(' + %d more', count($names) - count($shown));
            }
        }
        return $msg;
    }

    // away-cluster detection moved to VideoRenderJobScheduler
}
