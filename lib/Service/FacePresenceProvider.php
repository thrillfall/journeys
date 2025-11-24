<?php
namespace OCA\Journeys\Service;

use OCP\IDBConnection;

class FacePresenceProvider {
    public function __construct(
        private IDBConnection $db,
    ) {}

    /**
     * @param string $userId
     * @param int[] $fileIds
     * @return array<int,bool> fileid => hasFaces
     */
    public function getHasFacesByFileIds(string $userId, array $fileIds): array {
        if (empty($fileIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($fileIds), '?'));

        $sql = "
            SELECT file_id AS fileid, COUNT(*) AS face_count
            FROM oc_recognize_face_detections
            WHERE user_id = ?
              AND file_id IN ($placeholders)
            GROUP BY file_id
        ";

        $params = array_merge([$userId], array_map('intval', $fileIds));

        try {
            $stmt = $this->db->prepare($sql);
            $result = $stmt->execute($params);
            $rows = $result->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $fid = (int)$row['fileid'];
            $out[$fid] = ((int)$row['face_count'] > 0);
        }

        return $out;
    }
}
