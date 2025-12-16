<?php
namespace OCA\Journeys\Service;

use OCA\Journeys\Model\Image;

class ImageFetcher {
    public function __construct(
        private FacePresenceProvider $facePresenceProvider,
    ) {}

    /** @var array{total:int,home:int,group:int,shared:int} */
    private array $lastFetchStats = [
        'total' => 0,
        'home' => 0,
        'group' => 0,
        'shared' => 0,
    ];

    /** @var array<int,'home'|'group'|'shared'> */
    private array $lastFileSources = [];
    /**
     * Fetch all images indexed by Memories for a given user, with location and time_taken
     *
     * @param string $user
     * @param bool $includeGroupFolders Include images from Group Folders / external mounts
     * @param bool $includeSharedImages Include images available via user shares
     * @return Image[]
     */
    public function fetchImagesForUser(string $user, bool $includeGroupFolders = false, bool $includeSharedImages = false): array {
        // Get the DB connection from the server container
        $server = \OC::$server;
        $db = $server->getDatabaseConnection();

        $rowsById = [];
        $homeIds = [];
        $groupIds = [];
        $sharedIds = [];
        $storageId = 'home::' . $user;

        // Always include the user's home storage
        $sqlHome = "
            SELECT m.fileid, m.datetaken, m.lat, m.lon, m.w, m.h, f.path
            FROM oc_memories m
            JOIN oc_filecache f ON m.fileid = f.fileid
            JOIN oc_storages s ON f.storage = s.numeric_id
            WHERE s.id = ? AND f.path LIKE 'files/%' AND m.datetaken IS NOT NULL
        ";
        $stmtHome = $db->prepare($sqlHome);
        $resultHome = $stmtHome->execute([$storageId]);
        $homeRows = $resultHome ? $resultHome->fetchAll() : [];
        if (!empty($homeRows)) {
            foreach ($homeRows as $row) {
                $fid = (int)$row['fileid'];
                $rowsById[$fid] = $row;
                $homeIds[$fid] = true;
            }
        }

        // Optionally include other mounts (Group Folders, external storage, etc.)
        $sharedProviderClass = 'OCA\\Files_Sharing\\MountProvider';
        if ($includeGroupFolders) {
            $userFilesPrefix = '/' . $user . '/files/%';
            $sqlGroup = "
                SELECT DISTINCT m.fileid, m.datetaken, m.lat, m.lon, m.w, m.h, f.path
                FROM oc_memories m
                JOIN oc_filecache f ON m.fileid = f.fileid
                JOIN oc_storages s ON f.storage = s.numeric_id
                JOIN oc_mounts mo ON mo.storage_id = s.numeric_id
                WHERE mo.user_id = ?
                  AND s.id <> ?
                  AND m.datetaken IS NOT NULL
                  AND mo.mount_point LIKE ?
                  AND (mo.mount_provider_class IS NULL OR mo.mount_provider_class <> ?)
            ";
            $stmtGroup = $db->prepare($sqlGroup);
            $resultGroup = $stmtGroup->execute([$user, $storageId, $userFilesPrefix, $sharedProviderClass]);
            $groupRows = $resultGroup ? $resultGroup->fetchAll() : [];
            if (!empty($groupRows)) {
                foreach ($groupRows as $row) {
                    $fid = (int)$row['fileid'];
                    $rowsById[$fid] = $row;
                    $groupIds[$fid] = true;
                }
            }
        }

        // Optionally include images shared with the user (shared mounts)
        if ($includeSharedImages) {
            $sharedMountPrefix = '/' . $user . '/files/Shared with you%';
            $sqlShared = "
                SELECT DISTINCT m.fileid, m.datetaken, m.lat, m.lon, m.w, m.h, f.path
                FROM oc_memories m
                JOIN oc_filecache f ON m.fileid = f.fileid
                JOIN oc_storages s ON f.storage = s.numeric_id
                JOIN oc_mounts mo ON mo.storage_id = s.numeric_id
                WHERE mo.user_id = ?
                  AND m.datetaken IS NOT NULL
                  AND (
                        mo.mount_provider_class = ?
                        OR (mo.mount_provider_class IS NULL AND mo.mount_point LIKE ?)
                    )
            ";
            $stmtShared = $db->prepare($sqlShared);
            $resultShared = $stmtShared->execute([$user, $sharedProviderClass, $sharedMountPrefix]);
            $sharedRows = $resultShared ? $resultShared->fetchAll() : [];
            if (!empty($sharedRows)) {
                foreach ($sharedRows as $row) {
                    $fid = (int)$row['fileid'];
                    $rowsById[$fid] = $row;
                    $sharedIds[$fid] = true;
                }
            }
        }

        if (empty($rowsById)) {
            $this->lastFetchStats = [
                'total' => 0,
                'home' => 0,
                'group' => 0,
                'shared' => 0,
            ];
            $this->lastFileSources = [];
            return [];
        }

        $rows = array_values($rowsById);
        $fileIds = array_map(static function ($row) {
            return (int)$row['fileid'];
        }, $rows);

        $hasFaces = $this->facePresenceProvider->getHasFacesByFileIds($user, $fileIds);

        $images = [];
        foreach ($rows as $row) {
            $fid = (int)$row['fileid'];
            $images[] = new Image(
                $fid,
                $row['path'],
                $row['datetaken'],
                $row['lat'],
                $row['lon'],
                isset($row['w']) ? (int)$row['w'] : null,
                isset($row['h']) ? (int)$row['h'] : null,
                $hasFaces[$fid] ?? null,
            );
        }

        // Compute stats (group/shared exclude files already coming from home or group respectively)
        $groupOnlyIds = array_diff_key($groupIds, $homeIds);
        $homeAndGroup = $homeIds + $groupIds;
        $sharedOnlyIds = array_diff_key($sharedIds, $homeAndGroup);
        $this->lastFetchStats = [
            'total' => count($rowsById),
            'home' => count($homeIds),
            'group' => count($groupOnlyIds),
            'shared' => count($sharedOnlyIds),
        ];

        // Record source classification for debug.
        // Precedence: home > group > shared
        $sources = [];
        foreach ($rowsById as $fid => $_row) {
            $fid = (int)$fid;
            if (isset($homeIds[$fid])) {
                $sources[$fid] = 'home';
            } elseif (isset($groupIds[$fid])) {
                $sources[$fid] = 'group';
            } elseif (isset($sharedIds[$fid])) {
                $sources[$fid] = 'shared';
            }
        }
        $this->lastFileSources = $sources;

        return $images;
    }

    /**
     * @return array{total:int,home:int,group:int,shared:int}
     */
    public function getLastFetchStats(): array {
        return $this->lastFetchStats;
    }

    /**
     * @return array<int,'home'|'group'|'shared'>
     */
    public function getLastFileSources(): array {
        return $this->lastFileSources;
    }

    /**
     * Fetch images for the given user limited to the provided file IDs.
     *
     * @param string $user
     * @param int[] $fileIds
     * @return Image[]
     */
    public function fetchImagesByFileIds(string $user, array $fileIds): array {
        if (empty($fileIds)) {
            return [];
        }

        $server = \OC::$server;
        $db = $server->getDatabaseConnection();

        // Build placeholders for IN clause
        $placeholders = implode(',', array_fill(0, count($fileIds), '?'));
        $sql = "
            SELECT f.fileid,
                   COALESCE(m.datetaken, FROM_UNIXTIME(f.mtime)) AS datetaken,
                   m.lat, m.lon, m.w, m.h,
                   f.path
            FROM oc_filecache f
            LEFT JOIN oc_memories m ON m.fileid = f.fileid
            WHERE f.fileid IN ($placeholders)
        ";
        $params = array_map('intval', $fileIds);
        $stmt = $db->prepare($sql);
        $result = $stmt->execute($params);
        $rows = $result->fetchAll();
        $images = [];
        if (!empty($rows)) {
            $fileIdsActual = [];
            foreach ($rows as $row) {
                $fileIdsActual[] = (int)$row['fileid'];
            }
            $hasFaces = $this->facePresenceProvider->getHasFacesByFileIds($user, $fileIdsActual);

            foreach ($rows as $row) {
                $fid = (int)$row['fileid'];
                $images[] = new Image(
                    $fid,
                    $row['path'],
                    (string)$row['datetaken'],
                    $row['lat'] ?? null,
                    $row['lon'] ?? null,
                    isset($row['w']) ? (int)$row['w'] : null,
                    isset($row['h']) ? (int)$row['h'] : null,
                    $hasFaces[$fid] ?? null,
                );
            }
        }
        return $images;
    }
}
