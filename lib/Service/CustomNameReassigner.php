<?php
namespace OCA\Journeys\Service;

use Psr\Log\LoggerInterface;

/**
 * After a from-scratch re-clustering, re-attach previously user-set custom names to whichever
 * newly created cluster album has the highest file-ID overlap (Jaccard similarity) with the
 * old one. Names whose best match falls below the threshold are dropped.
 */
class CustomNameReassigner {
    private const JACCARD_THRESHOLD = 0.5;

    private AlbumCreator $albumCreator;
    private ?LoggerInterface $logger;

    public function __construct(AlbumCreator $albumCreator, ?LoggerInterface $logger = null) {
        $this->albumCreator = $albumCreator;
        $this->logger = $logger;
    }

    /**
     * @param string $userId
     * @param array<int, array{album_id:int, custom_name:string, file_ids:int[]}> $snapshot
     *        The pre-purge snapshot from AlbumCreator::getCustomNameSnapshot().
     * @return array{matched: array<int, array{old_name:string, album_id:int, jaccard:float}>, dropped: array<int, array{old_name:string, best_jaccard:float}>}
     */
    public function reassign(string $userId, array $snapshot): array {
        $report = ['matched' => [], 'dropped' => []];
        if (empty($snapshot)) {
            return $report;
        }

        $newAlbums = [];
        foreach ($this->albumCreator->getTrackedClusters($userId) as $row) {
            $albumId = (int)($row['album_id'] ?? 0);
            if ($albumId <= 0) {
                continue;
            }
            $fileIds = $this->albumCreator->getAlbumFileIdsForUser($userId, $albumId);
            if (empty($fileIds)) {
                continue;
            }
            $newAlbums[$albumId] = [
                'album_id' => $albumId,
                'name' => (string)($row['name'] ?? ''),
                'file_ids' => $fileIds,
            ];
        }
        if (empty($newAlbums)) {
            foreach ($snapshot as $entry) {
                $report['dropped'][] = ['old_name' => $entry['custom_name'], 'best_jaccard' => 0.0];
            }
            return $report;
        }

        // Build all (snapshot index, new album id, jaccard) candidate pairs above threshold.
        $candidates = [];
        foreach ($snapshot as $sIdx => $entry) {
            $oldSet = array_flip(array_map('intval', $entry['file_ids']));
            foreach ($newAlbums as $aid => $album) {
                $j = $this->jaccard($oldSet, $album['file_ids']);
                if ($j >= self::JACCARD_THRESHOLD) {
                    $candidates[] = ['s' => $sIdx, 'a' => $aid, 'j' => $j];
                }
            }
        }
        // Sort desc by Jaccard so each greedy step picks the strongest pair first.
        usort($candidates, static fn ($x, $y) => $y['j'] <=> $x['j']);

        $usedSnapshots = [];
        $usedAlbums = [];
        foreach ($candidates as $c) {
            if (isset($usedSnapshots[$c['s']]) || isset($usedAlbums[$c['a']])) {
                continue;
            }
            $entry = $snapshot[$c['s']];
            $albumId = $c['a'];
            $name = $entry['custom_name'];
            $okSet = $this->albumCreator->setCustomName($userId, $albumId, $name);
            if ($okSet) {
                $this->albumCreator->renamePhotosAlbum($userId, $albumId, $name);
                $report['matched'][] = ['old_name' => $name, 'album_id' => $albumId, 'jaccard' => $c['j']];
                $usedSnapshots[$c['s']] = true;
                $usedAlbums[$c['a']] = true;
            }
        }

        foreach ($snapshot as $sIdx => $entry) {
            if (isset($usedSnapshots[$sIdx])) {
                continue;
            }
            $best = 0.0;
            $oldSet = array_flip(array_map('intval', $entry['file_ids']));
            foreach ($newAlbums as $album) {
                $best = max($best, $this->jaccard($oldSet, $album['file_ids']));
            }
            $report['dropped'][] = ['old_name' => $entry['custom_name'], 'best_jaccard' => $best];
        }

        if ($this->logger !== null) {
            try {
                $this->logger->info('Journeys: custom name reassignment', [
                    'app' => 'journeys',
                    'userId' => $userId,
                    'matched' => count($report['matched']),
                    'dropped' => count($report['dropped']),
                ]);
            } catch (\Throwable $ignored) {}
        }

        return $report;
    }

    /**
     * Jaccard similarity between a flipped-old set and a list of new file IDs.
     * @param array<int,int> $oldFlip array_flip of old file_ids (keys are file ids)
     * @param int[] $newIds
     */
    private function jaccard(array $oldFlip, array $newIds): float {
        if (empty($oldFlip) && empty($newIds)) {
            return 0.0;
        }
        $intersection = 0;
        $newFlip = [];
        foreach ($newIds as $id) {
            $id = (int)$id;
            $newFlip[$id] = true;
            if (isset($oldFlip[$id])) {
                $intersection++;
            }
        }
        $union = count($oldFlip) + count($newFlip) - $intersection;
        if ($union <= 0) {
            return 0.0;
        }
        return $intersection / $union;
    }
}
