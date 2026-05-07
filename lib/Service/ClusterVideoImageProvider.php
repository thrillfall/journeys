<?php
namespace OCA\Journeys\Service;

use DateTimeImmutable;
use OCA\Journeys\Exception\ClusterNotFoundException;
use OCA\Journeys\Exception\NoImagesFoundException;
use OCA\Journeys\Model\ClusterVideoSelection;
use OCA\Journeys\Model\Image;
use OCA\Journeys\Service\AlbumCreator;
use OCP\IConfig;

class ClusterVideoImageProvider {
    public function __construct(
        private ImageFetcher $imageFetcher,
        private Clusterer $clusterer,
        private VideoStorySelector $selector,
        private AlbumCreator $albumCreator,
        private IConfig $config,
    ) {}

    /**
     * @param string $user
     * @param int $clusterIndex Zero-based cluster index
     * @param int $minGapSeconds
     * @param int $maxImages
     * @return ClusterVideoSelection
     * @throws ClusterNotFoundException if the requested cluster does not exist
     */
    public function getSelectedImages(string $user, int $clusterIndex, int $minGapSeconds, int $maxImages): ClusterVideoSelection {
        $images = $this->imageFetcher->fetchImagesForUser($user, false, true, null, null);
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
        $boostFaces = (bool)((int)$this->config->getUserValue($user, 'journeys', 'boostFaces', 1));
        $preferredOrientation = $this->resolvePreferredOrientation($user);
        $selected = $this->selector->selectImages($user, $clusterImages, $minGapSeconds, $maxImages, $boostFaces, $preferredOrientation);

        $clusterStart = $this->createDateTimeImmutable($clusterImages[0]->datetaken ?? null);
        $clusterEnd = $this->createDateTimeImmutable($clusterImages[count($clusterImages) - 1]->datetaken ?? null);
        $metadata = $this->resolveClusterMetadata($user, $clusterStart, $clusterEnd, $clusterIndex);
        $clusterName = $this->buildClusterName($clusterStart, $clusterEnd, $clusterIndex, $metadata['name'] ?? null);
        $clusterLocation = $metadata['location'] ?? null;

        $selectedCount = count($selected);
        $facesSelected = 0;
        foreach ($selected as $img) {
            if ($img instanceof Image && $img->hasFaces === true) {
                $facesSelected++;
            }
        }

        return new ClusterVideoSelection(
            $selected,
            $clusterIndex,
            $clusterStart,
            $clusterEnd,
            $clusterLocation,
            $clusterName,
            $boostFaces,
            $selectedCount,
            $facesSelected,
        );
    }

    /**
     * Select images by Photos album id (tracked cluster) without re-clustering.
     * Guarantees a 1:1 mapping albumId -> album files.
     *
     * @param string $user
     * @param int $albumId
     * @param int $minGapSeconds
     * @param int $maxImages
     * @return ClusterVideoSelection
     * @throws NoImagesFoundException
     */
    public function getSelectedImagesForAlbumId(string $user, int $albumId, int $minGapSeconds, int $maxImages, ?bool $boostFacesOverride = null, ?string $orientationOverride = null): ClusterVideoSelection {
        // Get fileids for the album (owned by this user)
        $fileIds = $this->albumCreator->getAlbumFileIdsForUser($user, $albumId);
        if (empty($fileIds)) {
            throw new NoImagesFoundException('No images found for album id');
        }

        // Fetch only those images by file ids (left join oc_memories, fallback to mtime)
        $wanted = $this->imageFetcher->fetchImagesByFileIds($user, $fileIds);
        if (empty($wanted)) {
            throw new NoImagesFoundException('No album images readable for this user');
        }

        usort($wanted, fn(Image $a, Image $b) => strtotime($a->datetaken) <=> strtotime($b->datetaken));

        $boostFaces = $boostFacesOverride !== null
            ? $boostFacesOverride
            : (bool)((int)$this->config->getUserValue($user, 'journeys', 'boostFaces', 1));
        $preferredOrientation = $orientationOverride !== null
            ? (in_array($orientationOverride, ['portrait', 'landscape'], true) ? $orientationOverride : 'portrait')
            : $this->resolvePreferredOrientation($user);

        // The landscape renderer drops every portrait-orientation file before timing,
        // so a portrait-biased selection produces a much shorter video than expected.
        // Pre-filter to landscape candidates (keeping unknowns as a safety fallback).
        if ($preferredOrientation === 'landscape') {
            $landscapeOnly = array_values(array_filter(
                $wanted,
                fn(Image $img) => $img->w === null || $img->h === null || $img->w >= $img->h,
            ));
            if (empty($landscapeOnly)) {
                throw new NoImagesFoundException('No landscape-orientation images in album');
            }
            $wanted = $landscapeOnly;
        }

        $clusterStart = $this->createDateTimeImmutable($wanted[0]->datetaken ?? null);
        $clusterEnd = $this->createDateTimeImmutable($wanted[count($wanted) - 1]->datetaken ?? null);

        // Scale image cap by trip duration so a 3-week journey gets a longer
        // recap than a weekend (still bounded so the video stays under ~5 min
        // at 2.5 s per image). $maxImages is the absolute upper bound — an
        // explicit --max-images on the CLI can still pin it lower.
        $effectiveMax = self::scaleMaxImagesByDaySpan($maxImages, $clusterStart, $clusterEnd);
        $selected = $this->selector->selectImages($user, $wanted, $minGapSeconds, $effectiveMax, $boostFaces, $preferredOrientation);

        // Lookup metadata from tracked clusters by album id
        $tracked = $this->albumCreator->getTrackedClusters($user);
        $resolvedIndex = 0;
        $clusterName = '';
        $clusterLocation = null;
        foreach (array_values($tracked) as $idx => $row) {
            if (isset($row['album_id']) && (int)$row['album_id'] === $albumId) {
                $resolvedIndex = $idx;
                $normalized = $this->normalizeTrackedRow($row) ?? [];
                $clusterName = (string)($normalized['name'] ?? '');
                $clusterLocation = $normalized['location'] ?? null;
                break;
            }
        }

        if ($clusterName === '') {
            $albumTitle = $this->albumCreator->getAlbumNameForUser($user, $albumId);
            if ($albumTitle !== null && trim($albumTitle) !== '') {
                $clusterName = $albumTitle;
            }
        }

        if ($clusterName === '') {
            $clusterName = $this->buildClusterName($clusterStart, $clusterEnd, $resolvedIndex, null);
        }

        $selectedCount = count($selected);
        $facesSelected = 0;
        foreach ($selected as $img) {
            if ($img instanceof Image && $img->hasFaces === true) {
                $facesSelected++;
            }
        }

        return new ClusterVideoSelection(
            $selected,
            $resolvedIndex,
            $clusterStart,
            $clusterEnd,
            $clusterLocation,
            $clusterName,
            $boostFaces,
            $selectedCount,
            $facesSelected,
        );
    }

    private function resolvePreferredOrientation(string $user): string {
        $default = 'portrait';
        $value = $this->config->getUserValue($user, 'journeys', 'videoOrientation', $default);
        if (!is_string($value) || $value === '') {
            return $default;
        }

        $value = strtolower(trim($value));
        return in_array($value, ['portrait', 'landscape'], true) ? $value : $default;
    }

    private function createDateTimeImmutable(?string $value): DateTimeImmutable {
        if ($value === null || $value === '') {
            return new DateTimeImmutable();
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable) {
            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return new DateTimeImmutable('@' . $timestamp);
            }

            return new DateTimeImmutable();
        }
    }

    private function buildClusterName(DateTimeImmutable $start, DateTimeImmutable $end, int $clusterIndex, ?string $trackedName): string {
        $trackedName = $trackedName !== null ? trim($trackedName) : '';
        if ($trackedName !== '') {
            return $trackedName;
        }

        $startLabel = $start->format('Y-m-d');
        $endLabel = $end->format('Y-m-d');
        $datePart = $startLabel === $endLabel ? $startLabel : ($startLabel . ' to ' . $endLabel);

        return sprintf('Cluster %02d %s', $clusterIndex + 1, $datePart);
    }

    /**
     * @return array{name:string,location:?string}|null
     */
    private function resolveClusterMetadata(string $user, DateTimeImmutable $start, DateTimeImmutable $end, int $clusterIndex): ?array {
        $tracked = $this->albumCreator->getTrackedClusters($user);
        if (empty($tracked)) {
            return null;
        }

        $candidates = [];
        $startTs = $start->getTimestamp();
        $endTs = $end->getTimestamp();

        if (isset($tracked[$clusterIndex])) {
            $normalized = $this->normalizeTrackedRow($tracked[$clusterIndex]);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        foreach ($tracked as $row) {
            $normalized = $this->normalizeTrackedRow($row);
            if ($normalized === null) {
                continue;
            }
            $rowStartTs = $normalized['start']?->getTimestamp();
            $rowEndTs = $normalized['end']?->getTimestamp();

            if ($rowStartTs !== null && $rowEndTs !== null) {
                if ($this->intervalsOverlap($startTs, $endTs, $rowStartTs, $rowEndTs)) {
                    return $normalized;
                }
            }

            if ($rowStartTs !== null) {
                $candidates[] = [$normalized, abs($rowStartTs - $startTs)];
            }
        }

        if (empty($candidates)) {
            return null;
        }

        usort($candidates, static fn(array $a, array $b) => $a[1] <=> $b[1]);
        return $candidates[0][0] ?? null;
    }

    private function normalizeTrackedRow(array $row): ?array {
        $autoName = isset($row['name']) ? trim((string)$row['name']) : '';
        $custom = isset($row['custom_name']) && $row['custom_name'] !== null ? trim((string)$row['custom_name']) : '';
        // The video title overlay uses the custom name when set; the auto-derived name is the fallback.
        $name = $custom !== '' ? $custom : $autoName;
        $location = isset($row['location']) && $row['location'] !== null ? trim((string)$row['location']) : null;

        $start = $this->safeParseDateTime($row['start_dt'] ?? null);
        $end = $this->safeParseDateTime($row['end_dt'] ?? null);

        if ($name === '' && $start === null && $end === null) {
            return null;
        }

        return [
            'name' => $name,
            'location' => $location,
            'start' => $start,
            'end' => $end,
        ];
    }

    private function safeParseDateTime(mixed $value): ?DateTimeImmutable {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function intervalsOverlap(int $aStart, int $aEnd, int $bStart, int $bEnd): bool {
        return !($aEnd < $bStart || $bEnd < $aStart);
    }

    /**
     * Pick the per-cluster image cap based on how many days the trip spanned.
     * Stays at the previous default of 80 for trips up to a week so short trips
     * are unchanged, then climbs by 4 images per extra day until the absolute
     * cap is reached (120 keeps the video at ~5 min with the default 2.5 s per
     * image).
     */
    public static function scaleMaxImagesByDaySpan(int $absoluteMax, DateTimeImmutable $start, DateTimeImmutable $end): int {
        $absoluteMax = max(1, $absoluteMax);
        $base = min(80, $absoluteMax);
        $deltaSeconds = $end->getTimestamp() - $start->getTimestamp();
        $daySpan = max(1, (int)floor($deltaSeconds / 86400) + 1);
        $extraDays = max(0, $daySpan - 7);
        return min($absoluteMax, $base + $extraDays * 4);
    }
}
