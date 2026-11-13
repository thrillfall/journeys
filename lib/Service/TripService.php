<?php
namespace OCA\Journeys\Service;

use OCP\IConfig;

class TripService {
    public function __construct(
        private AlbumCreator $albumCreator,
        private HomeService $homeService,
        private TripClassifier $classifier,
        private RenderedVideoLister $videoLister,
        private RenderedVideoRegistry $videoRegistry,
        private VideoRenderJobScheduler $scheduler,
        private IConfig $config,
    ) {}

    /**
     * @return array{journeys: array<int, array<string, mixed>>, homeKnown: bool, defaultOrientation: string}
     */
    public function listJourneys(string $userId): array {
        $home = $this->homeService->getHomeFromConfig($userId);
        $tracked = $this->sortNewestFirst($this->albumCreator->getTrackedClusters($userId));
        $videosByAlbum = $this->videosByAlbum($userId, $tracked);
        $pendingByAlbum = [];
        foreach ($this->scheduler->pendingForUser($userId) as $p) {
            $pendingByAlbum[$p['albumId']][] = $p['orientation'];
        }

        $journeys = [];
        foreach ($tracked as $row) {
            $albumId = (int)$row['album_id'];
            $isAway = $row['is_away'];
            if ($isAway === null && $home !== null) {
                $isAway = $this->classifier->isAway($this->albumCreator->getAlbumCoordinates($albumId), $home);
                if ($isAway !== null) {
                    $this->albumCreator->setIsAway($userId, $albumId, $isAway);
                }
            }
            $fileIds = $this->albumCreator->getAlbumFileIdsForUser($userId, $albumId);
            sort($fileIds);
            $journeys[] = [
                'id' => $albumId,
                'name' => $row['name'],
                'customName' => $row['custom_name'],
                'location' => $row['location'],
                'start' => $row['start_dt'],
                'end' => $row['end_dt'],
                'imageCount' => count($fileIds),
                'coverFileId' => $fileIds === [] ? null : $fileIds[intdiv(count($fileIds), 2)],
                'kind' => $this->classifier->kind($isAway, $row['start_dt'], $row['end_dt']),
                'videos' => $videosByAlbum[$albumId] ?? [],
                'pending' => array_values(array_unique($pendingByAlbum[$albumId] ?? [])),
            ];
        }

        return [
            'journeys' => $journeys,
            'homeKnown' => $home !== null,
            'defaultOrientation' => $this->config->getUserValue($userId, 'journeys', 'videoOrientation', 'portrait') === 'landscape' ? 'landscape' : 'portrait',
        ];
    }

    /**
     * @return array{videos: array<int, array<string, mixed>>, pending: array<int, array<string, mixed>>}
     */
    public function listVideos(string $userId): array {
        $tracked = $this->albumCreator->getTrackedClusters($userId);
        $namesByAlbum = [];
        foreach ($tracked as $row) {
            $namesByAlbum[(int)$row['album_id']] = $row['custom_name'] ?: $row['name'];
        }
        $albumName = function (int $albumId) use (&$namesByAlbum, $userId): string {
            if (!array_key_exists($albumId, $namesByAlbum)) {
                $namesByAlbum[$albumId] = (string)($this->albumCreator->getAlbumNameForUser($userId, $albumId) ?? '');
            }
            return $namesByAlbum[$albumId];
        };

        $videos = [];
        foreach ($this->assignVideos($userId, $tracked) as $v) {
            $v['albumName'] = $v['albumId'] !== null ? $albumName($v['albumId']) : null;
            $v['isJourney'] = $v['albumId'] !== null && $this->isTracked($tracked, $v['albumId']);
            $videos[] = $v;
        }

        $pending = [];
        foreach ($this->scheduler->pendingForUser($userId) as $p) {
            $pending[] = $p + ['albumName' => $albumName($p['albumId'])];
        }

        return ['videos' => $videos, 'pending' => $pending];
    }

    /**
     * Photos albums the user made by hand (everything not created by clustering).
     *
     * @return array<int, array{id:int, name:string}>
     */
    public function manualAlbums(string $userId): array {
        $trackedIds = $this->albumCreator->getTrackedAlbumIds($userId);
        $out = [];
        foreach ($this->albumCreator->getAllAlbumsForUser($userId) as $album) {
            if (in_array((int)$album['album_id'], $trackedIds, true)) {
                continue;
            }
            $out[] = ['id' => (int)$album['album_id'], 'name' => (string)$album['name']];
        }
        return $out;
    }

    public function enqueueRender(string $userId, int $albumId, string $orientation): bool {
        if ($this->albumCreator->getAlbumFileIdsForUser($userId, $albumId) === []) {
            return false;
        }
        $this->scheduler->enqueue($userId, $albumId, $orientation === 'landscape' ? 'landscape' : 'portrait');
        return true;
    }

    /**
     * @return array{id:int, name:?string, customName:?string}|null null when the album is not a tracked journey
     */
    public function rename(string $userId, int $albumId, ?string $customName): ?array {
        $autoName = null;
        foreach ($this->albumCreator->getTrackedClusters($userId) as $row) {
            if ((int)$row['album_id'] === $albumId) {
                $autoName = (string)$row['name'];
                break;
            }
        }
        if ($autoName === null) {
            return null;
        }
        $customName = $customName !== null && trim($customName) !== '' ? trim($customName) : null;
        if (!$this->albumCreator->setCustomName($userId, $albumId, $customName)) {
            throw new \RuntimeException('Failed to update custom name');
        }
        $title = $customName ?? $autoName;
        if ($title !== '') {
            $this->albumCreator->renamePhotosAlbum($userId, $albumId, $title);
        }
        return ['id' => $albumId, 'name' => $autoName, 'customName' => $customName];
    }

    /**
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function videosByAlbum(string $userId, array $tracked): array {
        $out = [];
        foreach ($this->assignVideos($userId, $tracked) as $v) {
            if ($v['albumId'] !== null) {
                $out[$v['albumId']][] = $v;
            }
        }
        return $out;
    }

    /**
     * Every file in the movies folder, newest first, with the album it was
     * rendered from. Videos rendered before journeys_videos existed are matched
     * by name, the way the old settings page did.
     *
     * @return array<int, array{fileId:int, name:string, path:string, mtime:int, size:int, orientation:string, albumId:?int}>
     */
    private function assignVideos(string $userId, array $tracked): array {
        $registry = $this->videoRegistry->byFileId($userId);
        $needles = [];
        foreach ($this->sortNewestFirst($tracked) as $row) {
            foreach ([$row['custom_name'], $row['name']] as $candidate) {
                $needle = $this->fileNameNeedle((string)$candidate);
                if ($needle !== '') {
                    $needles[] = [$needle, (int)$row['album_id']];
                }
            }
        }

        $out = [];
        foreach ($this->videoLister->listForUser($userId) as $video) {
            $fileId = (int)$video['fileId'];
            $albumId = $registry[$fileId]['albumId'] ?? null;
            $orientation = $registry[$fileId]['orientation']
                ?? (preg_match('/\blandscape\b/i', $video['name']) === 1 ? 'landscape' : 'portrait');
            if ($albumId === null) {
                $lower = strtolower($video['name']);
                foreach ($needles as [$needle, $candidateId]) {
                    if (str_contains($lower, $needle)) {
                        $albumId = $candidateId;
                        break;
                    }
                }
            }
            $out[] = $video + ['orientation' => $orientation, 'albumId' => $albumId];
        }
        return $out;
    }

    private function isTracked(array $tracked, int $albumId): bool {
        foreach ($tracked as $row) {
            if ((int)$row['album_id'] === $albumId) {
                return true;
            }
        }
        return false;
    }

    private function sortNewestFirst(array $tracked): array {
        usort($tracked, static function (array $a, array $b): int {
            $byEnd = strcmp((string)($b['end_dt'] ?? ''), (string)($a['end_dt'] ?? ''));
            if ($byEnd !== 0) {
                return $byEnd;
            }
            $byStart = strcmp((string)($b['start_dt'] ?? ''), (string)($a['start_dt'] ?? ''));
            return $byStart !== 0 ? $byStart : ((int)$b['album_id'] <=> (int)$a['album_id']);
        });
        return $tracked;
    }

    /** Mirrors VideoRenderPrimitives::sanitizeFileName() so the name matches what was written to disk. */
    private function fileNameNeedle(string $name): string {
        $name = str_replace(['\\', '/'], '-', $name);
        $name = preg_replace('/[^A-Za-z0-9\.\-_ ]+/', '', $name) ?? '';
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;
        return strtolower(trim($name));
    }
}
