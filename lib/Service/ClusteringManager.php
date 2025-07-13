<?php
namespace OCA\Journeys\Service;

use OCA\Journeys\Model\Image;

class ClusteringManager {
    private $imageFetcher;
    private $clusterer;
    private $albumCreator;
    private $locationResolver;

    public function __construct(ImageFetcher $imageFetcher, Clusterer $clusterer, AlbumCreator $albumCreator, ClusterLocationResolver $locationResolver) {
        $this->imageFetcher = $imageFetcher;
        $this->clusterer = $clusterer;
        $this->albumCreator = $albumCreator;
        $this->locationResolver = $locationResolver;
    }

    /**
     * Orchestrate fetching, clustering, and album creation for a user.
     * @param string $userId
     * @return array [clustersCreated => int, lastRun => string, error? => string]
     */
    public function clusterForUser(string $userId, int $maxTimeGap = 86400, float $maxDistanceKm = 100.0, int $minClusterSize = 3): array {
        // Purge cluster albums before creating new ones
        $purgedAlbums = $this->albumCreator->purgeClusterAlbums($userId);
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
        // Interpolate missing locations (match CLI default: 6h)
        $images = \OCA\Journeys\Service\ImageLocationInterpolator::interpolate($images, 21600);
        $clusters = $this->clusterer->clusterImages($images, $maxTimeGap, $maxDistanceKm);
        $created = 0;
        $clusterSummaries = [];
        foreach ($clusters as $i => $cluster) {
            if (count($cluster) < $minClusterSize) {
                continue;
            }
            $start = $cluster[0]->datetaken;
            $dtStart = new \DateTime($cluster[0]->datetaken);
            $dtEnd = new \DateTime($cluster[count($cluster)-1]->datetaken);
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
            $this->albumCreator->createAlbumWithImages($userId, $albumName, $cluster, $location ?? '');
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
