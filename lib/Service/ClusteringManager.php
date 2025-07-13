<?php
namespace OCA\Journeys\Service;

use OCA\Journeys\Model\Image;

class ClusteringManager {
    private $imageFetcher;
    private $clusterer;
    private $albumCreator;

    public function __construct(ImageFetcher $imageFetcher, Clusterer $clusterer, AlbumCreator $albumCreator) {
        $this->imageFetcher = $imageFetcher;
        $this->clusterer = $clusterer;
        $this->albumCreator = $albumCreator;
    }

    /**
     * Orchestrate fetching, clustering, and album creation for a user.
     * @param string $userId
     * @return array [clustersCreated => int, lastRun => string, error? => string]
     */
    public function clusterForUser(string $userId): array {
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
        $clusters = $this->clusterer->clusterImages($images);
        $created = 0;
        $clusterSummaries = [];
        foreach ($clusters as $cluster) {
            $start = $cluster[0]->datetaken;
            $end = $cluster[count($cluster)-1]->datetaken;
            $albumName = sprintf('Journey (%s to %s)', $start, $end);
            $this->albumCreator->createAlbumWithImages($userId, $albumName, $cluster);
            $clusterSummaries[] = [
                'albumName' => $albumName,
                'imageCount' => count($cluster)
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
