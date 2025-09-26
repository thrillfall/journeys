<?php
namespace OCA\Journeys\Service;

use DateTimeImmutable;
use OCA\Journeys\Exception\ClusterNotFoundException;
use OCA\Journeys\Exception\NoImagesFoundException;
use OCA\Journeys\Model\ClusterVideoSelection;
use OCA\Journeys\Model\Image;
use OCA\Journeys\Service\AlbumCreator;

class ClusterVideoImageProvider {
    public function __construct(
        private ImageFetcher $imageFetcher,
        private Clusterer $clusterer,
        private VideoStorySelector $selector,
        private AlbumCreator $albumCreator,
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
        $selected = $this->selector->selectImages($user, $clusterImages, $minGapSeconds, $maxImages);

        $clusterStart = $this->createDateTimeImmutable($clusterImages[0]->datetaken ?? null);
        $clusterEnd = $this->createDateTimeImmutable($clusterImages[count($clusterImages) - 1]->datetaken ?? null);
        $metadata = $this->resolveClusterMetadata($user, $clusterStart, $clusterEnd, $clusterIndex);
        $clusterName = $this->buildClusterName($clusterStart, $clusterEnd, $clusterIndex, $metadata['name'] ?? null);
        $clusterLocation = $metadata['location'] ?? null;

        return new ClusterVideoSelection(
            $selected,
            $clusterIndex,
            $clusterStart,
            $clusterEnd,
            $clusterLocation,
            $clusterName,
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
    public function getSelectedImagesForAlbumId(string $user, int $albumId, int $minGapSeconds, int $maxImages): ClusterVideoSelection {
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

        $selected = $this->selector->selectImages($user, $wanted, $minGapSeconds, $maxImages);

        $clusterStart = $this->createDateTimeImmutable($wanted[0]->datetaken ?? null);
        $clusterEnd = $this->createDateTimeImmutable($wanted[count($wanted) - 1]->datetaken ?? null);

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
            $clusterName = $this->buildClusterName($clusterStart, $clusterEnd, $resolvedIndex, null);
        }

        return new ClusterVideoSelection(
            $selected,
            $resolvedIndex,
            $clusterStart,
            $clusterEnd,
            $clusterLocation,
            $clusterName,
        );
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
        $name = isset($row['name']) ? trim((string)$row['name']) : '';
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
}
