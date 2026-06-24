<?php
namespace OCA\Journeys\Service;

use OCP\IDBConnection;

/**
 * Fetches a user's photos for a calendar day (or date range) from the Memories
 * index, for the travel-diary photo pickers. Mirrors ImageFetcher's home-storage
 * query but uses calendar-day string bounds on datetaken (the picker thinks in
 * days, not timestamps) and orders chronologically.
 *
 * v1: home storage only. Shared / group-folder sources are a later increment.
 */
class DiaryPhotoFetcher {

    public function __construct(
        private IDBConnection $db,
    ) {}

    /**
     * @return array<int,array{fileid:int,path:string,datetaken:string,lat:?string,lon:?string,w:?int,h:?int}>
     */
    public function fetchForDay(string $user, string $date): array {
        return $this->fetchForRange($user, $date, $date);
    }

    /**
     * @param string $fromDate inclusive 'Y-m-d'
     * @param string $toDate   inclusive 'Y-m-d'
     * @return array<int,array{fileid:int,path:string,datetaken:string,lat:?string,lon:?string,w:?int,h:?int}>
     */
    public function fetchForRange(string $user, string $fromDate, string $toDate): array {
        $from = $this->normalizeDate($fromDate);
        $to = $this->normalizeDate($toDate);
        if ($from === null || $to === null) {
            return [];
        }
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $storageId = 'home::' . $user;
        $sql = "
            SELECT m.fileid, m.datetaken, m.lat, m.lon, m.w, m.h, f.path
            FROM oc_memories m
            JOIN oc_filecache f ON m.fileid = f.fileid
            JOIN oc_storages s ON f.storage = s.numeric_id
            WHERE s.id = ? AND f.path LIKE 'files/%' AND m.datetaken IS NOT NULL
              AND f.path NOT LIKE 'files/Documents/Journeys Movies/%'
              AND m.datetaken >= ? AND m.datetaken <= ?
            ORDER BY m.datetaken ASC, m.fileid ASC
        ";
        $params = [$storageId, $from . ' 00:00:00', $to . ' 23:59:59'];
        $stmt = $this->db->prepare($sql);
        $result = $stmt->execute($params);
        $rows = $result ? $result->fetchAll() : [];

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'fileid' => (int)$row['fileid'],
                'path' => (string)$row['path'],
                'datetaken' => (string)$row['datetaken'],
                'lat' => $row['lat'] !== null ? (string)$row['lat'] : null,
                'lon' => $row['lon'] !== null ? (string)$row['lon'] : null,
                'w' => isset($row['w']) ? (int)$row['w'] : null,
                'h' => isset($row['h']) ? (int)$row['h'] : null,
            ];
        }
        return $out;
    }

    /** Validate/normalize a 'Y-m-d' date string; null if not a valid date. */
    private function normalizeDate(string $date): ?string {
        $date = trim($date);
        $dt = \DateTime::createFromFormat('!Y-m-d', $date);
        $errors = \DateTime::getLastErrors();
        if ($dt === false || ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }
        return $dt->format('Y-m-d');
    }
}
