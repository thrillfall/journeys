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
    public function clusterForUser(string $userId, int $maxTimeGap = 86400, float $maxDistanceKm = 100.0): array {
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
            $start = $cluster[0]->datetaken;
            $end = $cluster[count($cluster)-1]->datetaken;
            $location = $this->locationResolver->resolveClusterLocation($cluster, true);
            if ($location) {
                $albumName = sprintf('%s (%s to %s)', $location, $start, $end);
            } else {
                $albumName = sprintf('Journey %d (%s to %s)', $i+1, $start, $end);
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
            'clusters' => $clusterSummaries
        ];
    }
}
