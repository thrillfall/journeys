<?php
namespace OCA\Journeys\Service;

use OCP\IDBConnection;

/**
 * Fetches a user's photos for a calendar day (or date range) from the Memories
 * index, for the travel-diary photo pickers. Mirrors ImageFetcher's storage
 * scoping but uses calendar-day string bounds on datetaken (the picker thinks in
 * days, not timestamps) and orders chronologically.
 */
class DiaryPhotoFetcher {

    private const SHARED_PROVIDER = 'OCA\\Files_Sharing\\MountProvider';
    private const PHOTO_COLUMNS = 'm.fileid, m.datetaken, m.lat, m.lon, m.w, m.h, f.path';
    private const DAY_COLUMNS = 'm.fileid, m.datetaken';

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
        $window = $this->window($fromDate, $toDate);
        if ($window === null) {
            return [];
        }

        $out = [];
        foreach ($this->rowsInWindow($user, $window[0], $window[1]) as $row) {
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

    /**
     * A day's photos across several users' libraries, each row tagged with its
     * owner. Used by the picker once members have consented to share.
     *
     * @param string[] $users
     * @return array<int,array{fileid:int,ownerUid:string,path:string,datetaken:string,lat:?string,lon:?string,w:?int,h:?int}>
     */
    public function fetchForDayForUsers(array $users, string $date): array {
        $out = [];
        foreach (array_values(array_unique($users)) as $user) {
            foreach ($this->fetchForDay($user, $date) as $photo) {
                $photo['ownerUid'] = $user;
                $out[] = $photo;
            }
        }
        usort($out, static fn(array $a, array $b) => [$a['datetaken'], $a['fileid']] <=> [$b['datetaken'], $b['fileid']]);
        return $out;
    }

    /**
     * True if the fileid is an indexed image in $ownerUid's library whose
     * capture date falls inside the inclusive day window. This is the exposure
     * bound of a library consent, so it is enforced on every foreign read, and
     * it has to accept exactly what the picker offered.
     */
    public function isImageInWindow(int $fileid, string $ownerUid, string $fromDate, string $toDate): bool {
        $window = $this->window($fromDate, $toDate);
        if ($fileid <= 0 || $window === null) {
            return false;
        }
        return $this->rowsInWindow($ownerUid, $window[0], $window[1], $fileid) !== [];
    }

    /**
     * How many photos each calendar day in the range holds, for marking the
     * days worth writing about in the day picker. Days without photos are
     * absent rather than zero.
     *
     * Grouped in PHP because there is no portable date-truncating expression:
     * MySQL's DATE(), PostgreSQL's cast and SQLite's date() disagree on a
     * datetime column. The range is the month on screen, not a whole library.
     *
     * @return array<string,int> 'Y-m-d' => count
     */
    public function countsByDay(string $user, string $fromDate, string $toDate): array {
        $window = $this->window($fromDate, $toDate);
        if ($window === null) {
            return [];
        }
        $counts = [];
        foreach ($this->rowsInWindow($user, $window[0], $window[1], null, self::DAY_COLUMNS) as $row) {
            $day = substr((string)$row['datetaken'], 0, 10);
            $counts[$day] = ($counts[$day] ?? 0) + 1;
        }
        return $counts;
    }

    /**
     * Capture time per fileid from the Memories index, for merge sorting an
     * entry's photos chronologically across contributors.
     *
     * Deliberately not storage-scoped: an entry mixes photos from several users'
     * libraries, so scoping to one home storage would lose the other owners'
     * timestamps. It is only ever called for fileids that already passed the
     * entry-membership / ownership checks, and it returns no file content.
     *
     * @param int[] $fileids
     * @return array<int,?string> fileid => 'Y-m-d H:i:s' (absent when unindexed)
     */
    public function takenAtForFileIds(array $fileids): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $fileids), static fn(int $id) => $id > 0)));
        if (!$ids) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT fileid, datetaken FROM *PREFIX*memories WHERE fileid IN ({$placeholders}) AND datetaken IS NOT NULL";
        $stmt = $this->db->prepare($sql);
        $result = $stmt->execute($ids);
        $rows = $result ? $result->fetchAll() : [];

        $out = [];
        foreach ($rows as $row) {
            $out[(int)$row['fileid']] = (string)$row['datetaken'];
        }
        return $out;
    }

    /**
     * Indexed images of $user in the datetaken window, chronological.
     *
     * Two sources, merged by fileid: the user's home storage and every other
     * storage mounted into their own files tree (external storage, Group
     * Folders). Share mounts are left out - those are another user's library,
     * and a member's consent is what opens that door.
     *
     * @return array<int,array<string,mixed>>
     */
    private function rowsInWindow(string $user, string $from, string $to, ?int $fileid = null, string $columns = self::PHOTO_COLUMNS): array {
        $filter = $fileid !== null ? ' AND m.fileid = ?' : '';

        // Images only: Memories indexes videos too, and a bare video (e.g. a GCam
        // *.TS.mp4 / *.LS.mp4 motion clip sitting next to its still) has no image
        // preview, so seeding one produces an unloadable dark tile in the entry.
        //
        // 'object::user:<uid>' is the home storage id when Nextcloud runs on
        // object storage; matching 'home::<uid>' alone left the picker empty on
        // every such install while Memories still listed the photos.
        $homeSql = "
            SELECT DISTINCT {$columns}
            FROM *PREFIX*memories m
            JOIN *PREFIX*filecache f ON m.fileid = f.fileid
            JOIN *PREFIX*storages s ON f.storage = s.numeric_id
            JOIN *PREFIX*mimetypes mt ON f.mimetype = mt.id
            WHERE s.id IN (?, ?) AND f.path LIKE 'files/%' AND m.datetaken IS NOT NULL
              AND mt.mimetype LIKE 'image/%'
              AND f.path NOT LIKE 'files/Documents/Journeys Movies/%'
              AND m.datetaken >= ? AND m.datetaken <= ?" . $filter;
        $homeParams = ['home::' . $user, 'object::user:' . $user, $from, $to];

        $mountSql = "
            SELECT DISTINCT {$columns}
            FROM *PREFIX*memories m
            JOIN *PREFIX*filecache f ON m.fileid = f.fileid
            JOIN *PREFIX*mounts mo ON mo.storage_id = f.storage
            JOIN *PREFIX*mimetypes mt ON f.mimetype = mt.id
            WHERE mo.user_id = ? AND mo.mount_point LIKE ? AND m.datetaken IS NOT NULL
              AND (mo.mount_provider_class IS NULL OR mo.mount_provider_class <> ?)
              AND mt.mimetype LIKE 'image/%'
              AND f.path NOT LIKE 'files/Documents/Journeys Movies/%'
              AND m.datetaken >= ? AND m.datetaken <= ?" . $filter;
        $mountParams = [$user, '/' . $user . '/files/%', self::SHARED_PROVIDER, $from, $to];

        if ($fileid !== null) {
            $homeParams[] = $fileid;
            $mountParams[] = $fileid;
        }

        $byId = [];
        foreach ([[$homeSql, $homeParams], [$mountSql, $mountParams]] as [$sql, $params]) {
            $result = $this->db->prepare($sql)->execute($params);
            foreach (($result ? $result->fetchAll() : []) as $row) {
                $id = (int)$row['fileid'];
                if (!isset($byId[$id])) {
                    $byId[$id] = $row;
                }
            }
        }

        $rows = array_values($byId);
        usort($rows, static fn(array $a, array $b) => [(string)$a['datetaken'], (int)$a['fileid']] <=> [(string)$b['datetaken'], (int)$b['fileid']]);
        return $rows;
    }

    /**
     * Inclusive day bounds as datetaken strings, or null if either date is not
     * a valid 'Y-m-d'. Reversed input is swapped rather than rejected.
     *
     * @return array{0:string,1:string}|null
     */
    private function window(string $fromDate, string $toDate): ?array {
        $from = $this->normalizeDate($fromDate);
        $to = $this->normalizeDate($toDate);
        if ($from === null || $to === null) {
            return null;
        }
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        return [$from . ' 00:00:00', $to . ' 23:59:59'];
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
