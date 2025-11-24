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
    public function selectImages(string $userId, array $clusterImages, int $minGapSeconds = 5, int $maxImages = 80, bool $boostFaces = true): array {
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

        // Second pass: evenly sample candidates to spread coverage over the full journey.
        // When enabled, prefer images with higher story score (e.g. faces) in a local window.
        if (!empty($candidates)) {
            $target = max(1, min($maxImages, count($candidates)));
            return $boostFaces
                ? $this->sampleWithPreference($candidates, $target)
                : $this->evenlySample($candidates, $target);
        }

        // Fallback: evenly sample from the full cluster if no candidates after burst filtering.
        $fallbackTarget = max(1, min($maxImages, count($clusterImages)));
        return $boostFaces
            ? $this->sampleWithPreference($clusterImages, $fallbackTarget)
            : $this->evenlySample($clusterImages, $fallbackTarget);
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

    /**
     * Evenly sample N images, but for each target index prefer the highest-scoring
     * image (e.g. with faces) within a small local window around that index.
     * @param Image[] $images
     * @param int $n
     * @return Image[]
     */
    private function sampleWithPreference(array $images, int $n): array {
        $count = count($images);
        if ($n >= $count) return $images;
        if ($n <= 0) return [];

        $result = [];
        $used = [];
        $window = 2; // look +/- 2 positions around the target index

        for ($i = 0; $i < $n; $i++) {
            $baseIdx = (int) floor($i * ($count - 1) / max(1, $n - 1));
            $bestIdx = $baseIdx;
            $bestScore = $this->score($images[$baseIdx]);

            $start = max(0, $baseIdx - $window);
            $end = min($count - 1, $baseIdx + $window);

            for ($j = $start; $j <= $end; $j++) {
                $score = $this->score($images[$j]);
                if ($score > $bestScore || ($score === $bestScore && abs($j - $baseIdx) < abs($bestIdx - $baseIdx))) {
                    $bestScore = $score;
                    $bestIdx = $j;
                }
            }

            // Avoid picking the exact same index multiple times when possible
            if (isset($used[$bestIdx])) {
                $altIdx = $baseIdx;
                $altDist = PHP_INT_MAX;
                for ($j = $start; $j <= $end; $j++) {
                    if (!isset($used[$j])) {
                        $dist = abs($j - $baseIdx);
                        if ($dist < $altDist) {
                            $altDist = $dist;
                            $altIdx = $j;
                        }
                    }
                }
                if (!isset($used[$altIdx])) {
                    $bestIdx = $altIdx;
                }
            }

            $used[$bestIdx] = true;
            $result[] = $images[$bestIdx];
        }

        return $result;
    }

    /**
     * Compute a simple story score for an image. For now this prefers images with faces.
     */
    private function score(Image $img): float {
        $score = 1.0;
        if ($img->hasFaces === true) {
            $score += 2.0;
        }
        return $score;
    }
}
