<?php
namespace OCA\Journeys\Service;

use OCA\Journeys\Model\Image;

class VideoStorySelector {
    /**
     * Select a subset of images for a video story.
     * - Prefer temporal diversity: enforce a minimum gap between selected images.
     * - Later: prefer images with faces (via Recognize/system tags). For now, best-effort placeholder.
     *
     * @param string $userId
     * @param Image[] $clusterImages Images of a single cluster (sorted or unsorted)
     * @param int $minGapSeconds Minimum seconds between consecutive selected images
     * @param int $maxImages Optional cap to avoid overly long videos
     * @return Image[]
     */
    public function selectImages(string $userId, array $clusterImages, int $minGapSeconds = 5, int $maxImages = 80): array {
        if (empty($clusterImages)) return [];

        // Keep both portrait and landscape images. Portrait-first mixing is handled by the renderer,
        // which inserts occasional 3-wide landscape stacks. Do not filter by orientation here.
        $clusterImages = array_values(array_filter($clusterImages, function($img) {
            return $img instanceof Image;
        }));
        if (empty($clusterImages)) return [];

        // Ensure sorted by datetaken
        usort($clusterImages, fn($a,$b) => strtotime($a->datetaken) <=> strtotime($b->datetaken));

        // First pass: burst de-duplication across the whole cluster (no early cap)
        $candidates = [];
        $lastTs = null;
        foreach ($clusterImages as $img) {
            $ts = strtotime($img->datetaken);
            if ($ts === false) continue;
            if ($lastTs !== null && ($ts - $lastTs) < $minGapSeconds) continue;
            $candidates[] = $img;
            $lastTs = $ts;
        }

        // Second pass: evenly sample candidates to spread coverage over the full journey
        if (!empty($candidates)) {
            $target = max(1, min($maxImages, count($candidates)));
            return $this->evenlySample($candidates, $target);
        }

        // Fallback: evenly sample from the full cluster if no candidates after burst filtering
        $fallbackTarget = max(1, min($maxImages, count($clusterImages)));
        return $this->evenlySample($clusterImages, $fallbackTarget);
    }

    /**
     * Evenly sample N images from the array in-order.
     * @param Image[] $images
     * @param int $n
     * @return Image[]
     */
    private function evenlySample(array $images, int $n): array {
        $count = count($images);
        if ($n >= $count) return $images;
        if ($n <= 0) return [];
        $result = [];
        for ($i = 0; $i < $n; $i++) {
            $idx = (int) floor($i * ($count - 1) / max(1, $n - 1));
            $result[] = $images[$idx];
        }
        return $result;
    }
}
