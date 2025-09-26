<?php
namespace OCA\Journeys\Service;

use OCA\Journeys\Model\Image;

class ImageFetcher {
    /**
     * Fetch all images indexed by Memories for a given user, with location and time_taken
     * @param string $user
     * @return Image[]
     */
    public function fetchImagesForUser(string $user): array {
        // Get the DB connection from the server container
        $server = \OC::$server;
        $db = $server->getDatabaseConnection();

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
        $rows = $result->fetchAll();
        $images = [];
        foreach ($rows as $row) {
            $images[] = new Image(
                (int)$row['fileid'],
                $row['path'],
                $row['datetaken'],
                $row['lat'],
                $row['lon'],
                isset($row['w']) ? (int)$row['w'] : null,
                isset($row['h']) ? (int)$row['h'] : null,
            );
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
        foreach ($rows as $row) {
            $images[] = new Image(
                (int)$row['fileid'],
                $row['path'],
                (string)$row['datetaken'],
                $row['lat'] ?? null,
                $row['lon'] ?? null,
                isset($row['w']) ? (int)$row['w'] : null,
                isset($row['h']) ? (int)$row['h'] : null,
            );
        }
        return $images;
    }
}
