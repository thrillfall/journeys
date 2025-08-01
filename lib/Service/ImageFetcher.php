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
            SELECT m.fileid, m.datetaken, m.lat, m.lon, f.path
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
                $row['lon']
            );
        }
        return $images;
    }
}
