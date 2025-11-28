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
     * @param bool $boostFaces Whether to boost images containing people
     * @param string|null $preferredOrientation Either 'portrait', 'landscape', or null for no preference
     * @return Image[]
     */
    public function selectImages(string $userId, array $clusterImages, int $minGapSeconds = 5, int $maxImages = 80, bool $boostFaces = true, ?string $preferredOrientation = null): array {
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
            $selected = $boostFaces
                ? $this->sampleWithPreference($candidates, $target, $boostFaces, $preferredOrientation)
                : $this->evenlySample($candidates, $target);

            if ($preferredOrientation === 'portrait') {
                $selected = $this->enforcePortraitMix($selected, $candidates, $target, $boostFaces);
            }

            return $this->sortByDatetaken($selected);
        }

        // Fallback: evenly sample from the full cluster if no candidates after burst filtering.
        $fallbackTarget = max(1, min($maxImages, count($clusterImages)));
        $selected = $boostFaces
            ? $this->sampleWithPreference($clusterImages, $fallbackTarget, $boostFaces, $preferredOrientation)
            : $this->evenlySample($clusterImages, $fallbackTarget);

        if ($preferredOrientation === 'portrait') {
            $selected = $this->enforcePortraitMix($selected, $clusterImages, $fallbackTarget, $boostFaces);
        }

        return $this->sortByDatetaken($selected);
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
    private function sampleWithPreference(array $images, int $n, bool $boostFaces, ?string $preferredOrientation): array {
        $count = count($images);
        if ($n >= $count) return $images;
        if ($n <= 0) return [];

        $result = [];
        $used = [];
        $window = 2; // look +/- 2 positions around the target index

        for ($i = 0; $i < $n; $i++) {
            $baseIdx = (int) floor($i * ($count - 1) / max(1, $n - 1));
            $bestIdx = $baseIdx;
            $bestScore = $this->score($images[$baseIdx], $boostFaces, $preferredOrientation);

            $start = max(0, $baseIdx - $window);
            $end = min($count - 1, $baseIdx + $window);

            for ($j = $start; $j <= $end; $j++) {
                $score = $this->score($images[$j], $boostFaces, $preferredOrientation);
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
    private function score(Image $img, bool $boostFaces, ?string $preferredOrientation): float {
        $score = 1.0;
        if ($boostFaces && $img->hasFaces === true) {
            $score += 2.0;
        }
        if ($preferredOrientation === 'portrait') {
            if ($this->isPortrait($img)) {
                $score += 0.5;
            } elseif ($this->isLandscape($img)) {
                $score += 0.1;
            }
        }
        return $score;
    }

    private function enforcePortraitMix(array $selected, array $pool, int $target, bool $boostFaces): array {
        if ($target <= 0 || empty($selected) || empty($pool)) {
            return $selected;
        }

        $availableLandscapes = [];
        foreach ($pool as $candidate) {
            if ($candidate instanceof Image && $this->isLandscape($candidate)) {
                $availableLandscapes[$candidate->fileid] = $candidate;
            }
        }

        if (empty($availableLandscapes)) {
            return $selected;
        }

        $desiredLandscape = $this->desiredLandscapeCount($target, count($availableLandscapes));
        if ($desiredLandscape === 0) {
            return $selected;
        }

        $selectedLandscapeCount = 0;
        $portraitIndexes = [];
        foreach ($selected as $idx => $img) {
            if (!$img instanceof Image) {
                continue;
            }
            if ($this->isLandscape($img)) {
                $selectedLandscapeCount++;
            } else {
                // track portraits for potential replacement
                $portraitIndexes[] = ['idx' => $idx, 'score' => $this->score($img, $boostFaces, 'portrait')];
            }
        }

        if ($selectedLandscapeCount >= $desiredLandscape || empty($portraitIndexes ?? [])) {
            return $selected;
        }

        // Remove already selected landscapes from the pool
        foreach ($selected as $img) {
            if ($img instanceof Image) {
                unset($availableLandscapes[$img->fileid]);
            }
        }

        if (empty($availableLandscapes)) {
            return $selected;
        }

        $needed = min($desiredLandscape - $selectedLandscapeCount, count($availableLandscapes), count($portraitIndexes));
        if ($needed <= 0) {
            return $selected;
        }

        // Sort portraits ascending by score so we replace the weakest ones first
        usort($portraitIndexes, fn($a, $b) => $a['score'] <=> $b['score']);

        $landscapeCandidates = [];
        foreach ($availableLandscapes as $candidate) {
            $landscapeCandidates[] = [
                'image' => $candidate,
                'score' => $this->score($candidate, $boostFaces, 'portrait'),
            ];
        }

        usort($landscapeCandidates, fn($a, $b) => $b['score'] <=> $a['score']);

        $result = $selected;
        for ($i = 0; $i < $needed; $i++) {
            if (!isset($portraitIndexes[$i], $landscapeCandidates[$i])) {
                break;
            }
            $replaceIdx = $portraitIndexes[$i]['idx'];
            $result[$replaceIdx] = $landscapeCandidates[$i]['image'];
        }

        return $result;
    }

    private function desiredLandscapeCount(int $target, int $availableLandscapes): int {
        if ($availableLandscapes === 0 || $target <= 3) {
            return 0;
        }

        $proportion = (int)round($target * 0.18);
        if ($target >= 12) {
            $proportion = max($proportion, 3);
        } elseif ($target >= 8) {
            $proportion = max($proportion, 2);
        } elseif ($target >= 5) {
            $proportion = max($proportion, 1);
        }

        $proportion = min($proportion, $availableLandscapes, max(0, $target - 2));
        if ($proportion <= 0) {
            return 0;
        }

        $stacks = intdiv($proportion, 3) * 3;
        if ($stacks === 0 && $availableLandscapes >= 3 && $target >= 9) {
            $stacks = 3;
        }

        return min($stacks, $availableLandscapes);
    }

    private function sortByDatetaken(array $images): array {
        usort($images, function($a, $b) {
            if (!$a instanceof Image || !$b instanceof Image) {
                return 0;
            }
            return strtotime($a->datetaken) <=> strtotime($b->datetaken);
        });
        return $images;
    }

    private function isLandscape(Image $img): bool {
        if ($img->w !== null && $img->h !== null && $img->w > 0 && $img->h > 0) {
            return $img->w > $img->h;
        }
        return false;
    }

    private function isPortrait(Image $img): bool {
        if ($img->w !== null && $img->h !== null && $img->w > 0 && $img->h > 0) {
            return $img->h >= $img->w;
        }
        return true;
    }
}
