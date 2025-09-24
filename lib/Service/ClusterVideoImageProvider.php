<?php
namespace OCA\Journeys\Service;

use OCA\Journeys\Exception\ClusterNotFoundException;
use OCA\Journeys\Exception\NoImagesFoundException;
use OCA\Journeys\Model\Image;

class ClusterVideoImageProvider {
    public function __construct(
        private ImageFetcher $imageFetcher,
        private Clusterer $clusterer,
        private VideoStorySelector $selector,
    ) {}

    /**
     * @param string $user
     * @param int $clusterIndex Zero-based cluster index
     * @param int $minGapSeconds
     * @param int $maxImages
     * @return Image[]
     * @throws ClusterNotFoundException if the requested cluster does not exist
     */
    public function getSelectedImages(string $user, int $clusterIndex, int $minGapSeconds, int $maxImages): array {
        $images = $this->imageFetcher->fetchImagesForUser($user);
        if (empty($images)) {
            throw new NoImagesFoundException('No images found for user.');
        }

        // Sort by datetaken to ensure deterministic clustering
        usort($images, fn(Image $a, Image $b) => strtotime($a->datetaken) <=> strtotime($b->datetaken));

        $clusters = $this->clusterer->clusterImages($images, 24 * 3600, 50.0);
        if ($clusterIndex < 0 || $clusterIndex >= count($clusters)) {
            throw new ClusterNotFoundException(
                sprintf('Cluster %d not found. Found %d clusters.', $clusterIndex + 1, count($clusters))
            );
        }

        $clusterImages = $clusters[$clusterIndex];
        return $this->selector->selectImages($user, $clusterImages, $minGapSeconds, $maxImages);
    }
}
