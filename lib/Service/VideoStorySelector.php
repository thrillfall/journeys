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

        // Ensure sorted by datetaken
        usort($clusterImages, fn($a,$b) => strtotime($a->datetaken) <=> strtotime($b->datetaken));

        $selected = [];
        $lastTs = null;

        foreach ($clusterImages as $img) {
            $ts = strtotime($img->datetaken);
            if ($ts === false) continue;

            // Burst dedupe: enforce minimum time gap
            if ($lastTs !== null && ($ts - $lastTs) < $minGapSeconds) {
                continue;
            }

            // Placeholder for face preference: accept all for now.
            // Future: integrate Recognize/system tags to require face presence.
            $selected[] = $img;
            $lastTs = $ts;

            if (count($selected) >= $maxImages) break;
        }

        // If too few images after burst filtering, fallback to evenly spacing
        if (count($selected) < min(12, count($clusterImages))) {
            $target = min(max(12, count($selected)), min(80, count($clusterImages)));
            $even = $this->evenlySample($clusterImages, $target);
            // Merge unique by path
            $seen = [];
            foreach ($selected as $s) { $seen[$s->path] = true; }
            foreach ($even as $e) {
                if (!isset($seen[$e->path])) {
                    $selected[] = $e;
                    $seen[$e->path] = true;
                }
                if (count($selected) >= $target) break;
            }
        }

        return $selected;
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
