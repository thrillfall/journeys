<?php
namespace OCA\Journeys\Service;

use OCA\Journeys\Model\Image;

class ImageFetcher {
    public function __construct(
        private FacePresenceProvider $facePresenceProvider,
    ) {}
    /**
     * Fetch all images indexed by Memories for a given user, with location and time_taken
     * @param string $user
     * @param bool $includeGroupFolders
     * @return Image[]
     */
    public function fetchImagesForUser(string $user, bool $includeGroupFolders = false): array {
        // Get the DB connection from the server container
        $server = \OC::$server;
        $db = $server->getDatabaseConnection();

        $params = [];
        if ($includeGroupFolders) {
            $sql = "
                SELECT m.fileid, m.datetaken, m.lat, m.lon, m.w, m.h, f.path
                FROM oc_memories m
                JOIN oc_filecache f ON m.fileid = f.fileid
                JOIN oc_storages s ON f.storage = s.numeric_id
                JOIN oc_mounts mo ON mo.storage_id = s.numeric_id AND mo.user_id = ?
                WHERE m.datetaken IS NOT NULL
            ";
            $stmt = $db->prepare($sql);
            $result = $stmt->execute([$user]);
        } else {
            $storageId = 'home::' . $user;
            $sql = "
                SELECT m.fileid, m.datetaken, m.lat, m.lon, m.w, m.h, f.path
                FROM oc_memories m
                JOIN oc_filecache f ON m.fileid = f.fileid
                JOIN oc_storages s ON f.storage = s.numeric_id
                WHERE s.id = ? AND f.path LIKE 'files/%' AND m.datetaken IS NOT NULL
            ";
            $stmt = $db->prepare($sql);
            $result = $stmt->execute([$storageId]);
        }
        $rows = $result->fetchAll();
        $images = [];
        if (!empty($rows)) {
            $fileIds = [];
            foreach ($rows as $row) {
                $fileIds[] = (int)$row['fileid'];
            }
            $hasFaces = $this->facePresenceProvider->getHasFacesByFileIds($user, $fileIds);

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
        }
        return $images;
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
