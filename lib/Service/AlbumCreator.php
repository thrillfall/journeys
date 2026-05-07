<?php
namespace OCA\Journeys\Service;

use OCA\Photos\Album\AlbumMapper;
use OCP\IUserManager;
use OCP\Files\IRootFolder;
use OCP\SystemTag\ISystemTagManager;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use OCA\Journeys\Model\Image;

class AlbumCreator {
    /**
     * Delete all albums created by the clusterer for a user (tracked in journeys_cluster_albums table).
     *
     * @param string $userId
     * @return int Number of albums deleted
     */
    public function purgeClusterAlbums(string $userId): int {
        $deleted = 0;
        try {
            // Fetch tracked album ids for this user
            $table = $this->getTrackingTableName();
            $stmt = $this->db->prepare("SELECT album_id FROM {$table} WHERE user_id = ?");
            $result = $stmt->execute([$userId]);
            $rows = $result->fetchAll();
            foreach ($rows as $row) {
                try {
                    $this->albumMapper->delete((int)$row['album_id']);
                    $deleted++;
                } catch (\Throwable $e) {
                    // ignore and continue
                }
            }
            // Clear tracking rows for this user regardless of deletion outcome
            $delStmt = $this->db->prepare("DELETE FROM {$table} WHERE user_id = ?");
            $delStmt->execute([$userId]);
        } catch (\Throwable $e) {
            // if anything goes wrong, return what we managed to delete
        }
        return $deleted;
    }

    /**
     * Delete tracked clusterer-created albums that have become empty
     * (e.g. the user removed all the photos the album referenced) and
     * drop tracking rows for albums that were already removed externally.
     *
     * @param string $userId
     * @return int Number of albums deleted from the Photos app
     */
    public function pruneEmptyClusterAlbums(string $userId): int {
        $deleted = 0;
        $table = $this->getTrackingTableName();
        try {
            $stmt = $this->db->prepare("SELECT album_id FROM {$table} WHERE user_id = ?");
            $result = $stmt->execute([$userId]);
            $rows = $result ? $result->fetchAll() : [];
        } catch (\Throwable $e) {
            return 0;
        }
        foreach ($rows as $row) {
            $albumId = isset($row['album_id']) ? (int)$row['album_id'] : 0;
            if ($albumId <= 0) {
                continue;
            }
            try {
                $ownStmt = $this->db->prepare('SELECT album_id FROM *PREFIX*photos_albums WHERE album_id = ? AND user = ?');
                $ownRes = $ownStmt->execute([$albumId, $userId]);
                $ownRow = $ownRes ? $ownRes->fetch() : false;
            } catch (\Throwable $e) {
                continue;
            }
            if ($ownRow === false) {
                // Album no longer exists or was reassigned; drop the stale tracking row.
                $this->deleteTrackingRow($userId, $albumId);
                continue;
            }
            try {
                $countStmt = $this->db->prepare('SELECT 1 FROM *PREFIX*photos_albums_files WHERE album_id = ? LIMIT 1');
                $countRes = $countStmt->execute([$albumId]);
                $hasFiles = $countRes ? ($countRes->fetch() !== false) : true;
            } catch (\Throwable $e) {
                continue;
            }
            if ($hasFiles) {
                continue;
            }
            try {
                $this->albumMapper->delete($albumId);
                $deleted++;
            } catch (\Throwable $e) {
                try {
                    $this->logger->warning('Journeys: failed to delete empty cluster album', [
                        'app' => 'journeys',
                        'userId' => $userId,
                        'albumId' => $albumId,
                        'exception' => $e->getMessage(),
                    ]);
                } catch (\Throwable $ignored) {}
                // Leave tracking row in place so we retry next run.
                continue;
            }
            $this->deleteTrackingRow($userId, $albumId);
        }
        return $deleted;
    }

    private function deleteTrackingRow(string $userId, int $albumId): void {
        try {
            $table = $this->getTrackingTableName();
            $stmt = $this->db->prepare("DELETE FROM {$table} WHERE user_id = ? AND album_id = ?");
            $stmt->execute([$userId, $albumId]);
        } catch (\Throwable $e) {
            // best-effort
        }
    }
    public const CLUSTERER_MARKER = '[clusterer]';
    private AlbumMapper $albumMapper;
    private IUserManager $userManager;
    private IRootFolder $rootFolder;
    private ISystemTagManager $systemTagManager;
    private IDBConnection $db;
    private LoggerInterface $logger;

    private const JOURNEYS_TAG = 'journeys-album';

    public function __construct(AlbumMapper $albumMapper, IUserManager $userManager, IRootFolder $rootFolder, ISystemTagManager $systemTagManager, IDBConnection $db, LoggerInterface $logger) {
        $this->albumMapper = $albumMapper;
        $this->userManager = $userManager;
        $this->rootFolder = $rootFolder;
        $this->systemTagManager = $systemTagManager;
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Create an album and add images to it for a user.
     *
     * @param string $userId
     * @param string $albumName
     * @param Image[] $images
     * @param string $location
     * @return void
     */
    public function createAlbumWithImages(string $userId, string $albumName, array $images, string $location = '', ?\DateTime $dtStart = null, ?\DateTime $dtEnd = null, ?string $customName = null): ?int {
        $albumName = $this->sanitizeAlbumTitle($albumName);
        $effectiveTitle = $albumName;
        if ($customName !== null && trim($customName) !== '') {
            $effectiveTitle = $this->sanitizeAlbumTitle($customName);
        }
        // Always attempt to create a new album; do not reuse by name to avoid collisions with manually created albums
        try {
            $album = $this->albumMapper->create($userId, $effectiveTitle, $location);
        } catch (\Throwable $e) {
            // If creation fails (e.g., name collision), log and skip this album without falling back to getByName
            try {
                $this->logger->warning('Journeys: skipping album creation due to error (likely name collision)', [
                    'app' => 'journeys',
                    'userId' => $userId,
                    'albumName' => $albumName,
                    'location' => $location,
                    'exception' => $e->getMessage(),
                ]);
            } catch (\Throwable $ignored) {
                // ignore logging failures
            }
            return null;
        }
        foreach ($images as $image) {
            // Use direct fileid from Memories index for all storage sources
            $fileId = isset($image->fileid) ? (int)$image->fileid : null;
            if ($fileId === null || $fileId <= 0) {
                continue;
            }
            try {
                $this->albumMapper->addFile($album->getId(), $fileId, $userId);
            } catch (\Throwable $e) {
                // Ignore if image is already in album or other non-fatal errors
            }
        }
        // Track this album as clusterer-created for reliable purge and incremental boundary detection.
        // The tracking row stores the auto-derived name (recoverable source of truth) and any custom_name
        // separately, even though the Photos album title may already be the custom one.
        $this->trackClusterAlbum($userId, (int)$album->getId(), $albumName, $location, $dtStart, $dtEnd, $customName);
        // Assign SystemTag to album folder (in addition to postfix logic)
        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
            $albumFolder = $userFolder->get($album->getTitle());
            // Ensure the journeysClusterTag exists
            $tags = $this->systemTagManager->getTagsByName(self::JOURNEYS_TAG);
            if (empty($tags)) {
                $journeysClusterTag = $this->systemTagManager->createTag(self::JOURNEYS_TAG, false, false);
            } else {
                $journeysClusterTag = array_values($tags)[0];
            }
            $this->systemTagManager->assignTag($albumFolder, $journeysClusterTag->getId());
        } catch (\Throwable $e) {
            // Tagging is best-effort; ignore errors
        }
        return (int)$album->getId();
    }

    /**
     * Resolve the fileId for a given Image object.
     *
     * @param string $userId
     * @param Image $image
     * @return int|null
     */
    private function getFileIdForImage(string $userId, Image $image): ?int {
        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
            // The memories/photos index returns paths prefixed with 'files/',
            // but Nextcloud's virtual filesystem expects paths relative to the user's root.
            $path = $image->path;
            if (strpos($path, 'files/') === 0) {
                $path = substr($path, 6); // Remove 'files/'
            }
            $node = $userFolder->get($path);
            return $node->getId();
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function getAlbumNameForUser(string $userId, int $albumId): ?string {
        try {
            $stmt = $this->db->prepare('SELECT name FROM *PREFIX*photos_albums WHERE album_id = ? AND user = ?');
            $res = $stmt->execute([$albumId, $userId]);
            $row = $res ? $res->fetch() : false;
            if ($row === false || !isset($row['name'])) {
                return null;
            }

            $name = (string)$row['name'];
            return $name !== '' ? $name : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function getTrackingTableName(): string {
        // Use Nextcloud SQL prefix placeholder; it is expanded by the DB layer
        return '*PREFIX*journeys_cluster_albums';
    }

    /**
     * Reset the latest cluster end by deleting all tracking rows for a user.
     * This effectively resets the "last end" to null for future computations.
     *
     * @param string $userId
     * @return void
     */
    public function resetLatestClusterEnd(string $userId): void {
        try {
            $table = $this->getTrackingTableName();
            $stmt = $this->db->prepare("DELETE FROM {$table} WHERE user_id = ?");
            $stmt->execute([$userId]);
        } catch (\Throwable $e) {
            // best-effort, ignore errors
        }
    }

    /**
     * Track a clusterer-created album for a user.
     */
    private function trackClusterAlbum(string $userId, int $albumId, string $name, string $location, ?\DateTime $dtStart, ?\DateTime $dtEnd, ?string $customName = null): void {
        $table = $this->getTrackingTableName();
        // Delete existing row (if any) then insert, to avoid DB-specific upsert syntax
        $del = $this->db->prepare("DELETE FROM {$table} WHERE user_id = ? AND album_id = ?");
        $delResult = $del->execute([$userId, $albumId]);
        if ($delResult === false) {
            throw new \RuntimeException('Failed to delete existing tracking row for cluster album');
        }
        $ins = $this->db->prepare("INSERT INTO {$table} (user_id, album_id, name, location, start_dt, end_dt, custom_name) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $normalizedCustom = ($customName !== null && trim($customName) !== '') ? trim($customName) : null;
        $insResult = $ins->execute([
            $userId,
            $albumId,
            $name,
            $location,
            $dtStart ? $dtStart->format('Y-m-d H:i:s') : null,
            $dtEnd ? $dtEnd->format('Y-m-d H:i:s') : null,
            $normalizedCustom,
        ]);
        if ($insResult === false) {
            throw new \RuntimeException('Failed to insert tracking row for cluster album');
        }
    }

    /**
     * Set or clear the user's custom display name for a tracked cluster album.
     * Empty string or null clears the custom name (display falls back to auto-derived name).
     */
    public function setCustomName(string $userId, int $albumId, ?string $customName): bool {
        $normalized = ($customName !== null && trim($customName) !== '') ? trim($customName) : null;
        try {
            $table = $this->getTrackingTableName();
            $stmt = $this->db->prepare("UPDATE {$table} SET custom_name = ? WHERE user_id = ? AND album_id = ?");
            $res = $stmt->execute([$normalized, $userId, $albumId]);
            return $res !== false;
        } catch (\Throwable $e) {
            try {
                $this->logger->warning('Journeys: failed to set custom_name', [
                    'app' => 'journeys',
                    'userId' => $userId,
                    'albumId' => $albumId,
                    'exception' => $e->getMessage(),
                ]);
            } catch (\Throwable $ignored) {}
            return false;
        }
    }

    /**
     * Rename the underlying Photos album. Best-effort: updates oc_photos_albums.name directly
     * (AlbumMapper has no public rename method across NC 30–33). Returns true on success.
     */
    public function renamePhotosAlbum(string $userId, int $albumId, string $newTitle): bool {
        $sanitized = $this->sanitizeAlbumTitle($newTitle);
        if ($sanitized === '') {
            return false;
        }
        try {
            $stmt = $this->db->prepare('UPDATE *PREFIX*photos_albums SET name = ? WHERE album_id = ? AND user = ?');
            $res = $stmt->execute([$sanitized, $albumId, $userId]);
            return $res !== false;
        } catch (\Throwable $e) {
            try {
                $this->logger->warning('Journeys: failed to rename Photos album', [
                    'app' => 'journeys',
                    'userId' => $userId,
                    'albumId' => $albumId,
                    'exception' => $e->getMessage(),
                ]);
            } catch (\Throwable $ignored) {}
            return false;
        }
    }

    /**
     * Snapshot custom names along with the file IDs of each album, captured BEFORE a from-scratch
     * re-clustering purge. Used by CustomNameReassigner to re-attach the names to the closest new
     * cluster by Jaccard overlap.
     *
     * @return array<int, array{album_id:int, custom_name:string, file_ids:int[]}>
     */
    public function getCustomNameSnapshot(string $userId): array {
        $out = [];
        try {
            $table = $this->getTrackingTableName();
            $stmt = $this->db->prepare("SELECT album_id, custom_name FROM {$table} WHERE user_id = ? AND custom_name IS NOT NULL AND custom_name <> ''");
            $res = $stmt->execute([$userId]);
            $rows = $res ? $res->fetchAll() : [];
        } catch (\Throwable $e) {
            return [];
        }
        foreach ($rows as $row) {
            $albumId = isset($row['album_id']) ? (int)$row['album_id'] : 0;
            $customName = isset($row['custom_name']) ? (string)$row['custom_name'] : '';
            if ($albumId <= 0 || $customName === '') {
                continue;
            }
            $fileIds = $this->getAlbumFileIdsForUser($userId, $albumId);
            if (empty($fileIds)) {
                continue;
            }
            $out[] = [
                'album_id' => $albumId,
                'custom_name' => $customName,
                'file_ids' => $fileIds,
            ];
        }
        return $out;
    }

    /**
     * Sanitize album titles to avoid filesystem/path issues in Photos and user folders.
     * Replaces forward and back slashes with a hyphen and normalizes whitespace.
     */
    private function sanitizeAlbumTitle(string $title): string {
        $title = str_replace(["/", "\\"], ' - ', $title);
        $title = preg_replace('/\s{2,}/', ' ', $title) ?? $title;
        return trim($title);
    }

    /**
     * Get the latest cluster end datetime for a user from tracking table.
     * @return \DateTimeInterface|null
     */
    public function getLatestClusterEnd(string $userId): ?\DateTimeInterface {
        try {
            $table = $this->getTrackingTableName();
            $stmt = $this->db->prepare("SELECT MAX(end_dt) AS max_end FROM {$table} WHERE user_id = ?");
            $result = $stmt->execute([$userId]);
            $row = $result->fetch();
            if ($row && !empty($row['max_end'])) {
                return new \DateTime($row['max_end']);
            }
        } catch (\Throwable $e) {
            // ignore and fallback to null
        }
        return null;
    }

    /**
     * Check if there are any previously tracked clusterer-created albums for this user.
     */
    public function hasTrackedAlbums(string $userId): bool {
        try {
            $table = $this->getTrackingTableName();
            $stmt = $this->db->prepare("SELECT 1 FROM {$table} WHERE user_id = ? LIMIT 1");
            $result = $stmt->execute([$userId]);
            $row = $result->fetch();
            return $row !== false && $row !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Return tracked album IDs for a user.
     * @return int[]
     */
    public function getTrackedAlbumIds(string $userId): array {
        try {
            $table = $this->getTrackingTableName();
            $stmt = $this->db->prepare("SELECT album_id FROM {$table} WHERE user_id = ?");
            $result = $stmt->execute([$userId]);
            $ids = [];
            while ($row = $result->fetch()) {
                if (isset($row['album_id'])) {
                    $ids[] = (int)$row['album_id'];
                }
            }
            return $ids;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Fetch every Photos album owned by the provided user directly from the Photos tables.
     *
     * @param string $userId
     * @return array<int, array{album_id:int,name:string}>
     */
    public function getAllAlbumsForUser(string $userId): array {
        try {
            $stmt = $this->db->prepare('SELECT album_id, name FROM *PREFIX*photos_albums WHERE user = ? ORDER BY name ASC, album_id ASC');
            $result = $stmt->execute([$userId]);
            $rows = $result ? $result->fetchAll() : [];
            if (!is_array($rows)) {
                return [];
            }

            return array_map(static function ($row) {
                return [
                    'album_id' => isset($row['album_id']) ? (int)$row['album_id'] : 0,
                    'name' => isset($row['name']) ? (string)$row['name'] : '',
                ];
            }, $rows);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Best-effort fetch of file IDs contained in a Photos album using AlbumMapper API.
     * @return int[]
     */
    public function getAlbumFileIds(int $albumId): array {
        try {
            if (method_exists($this->albumMapper, 'getFiles')) {
                $files = $this->albumMapper->getFiles($albumId);
                $ids = [];
                foreach ($files as $f) {
                    // Try common access patterns
                    if (is_object($f)) {
                        if (method_exists($f, 'getFileId')) {
                            $ids[] = (int)$f->getFileId();
                        } elseif (isset($f->fileId)) {
                            $ids[] = (int)$f->fileId;
                        }
                    } elseif (is_array($f)) {
                        if (isset($f['file_id'])) {
                            $ids[] = (int)$f['file_id'];
                        } elseif (isset($f['fileId'])) {
                            $ids[] = (int)$f['fileId'];
                        }
                    }
                }
                return $ids;
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return [];
    }

    /**
     * Get file IDs for an album owned by a specific user using Photos tables directly.
     * This is robust against AlbumMapper API variations and returns exactly the files assigned to the album.
     *
     * @param string $userId
     * @param int $albumId
     * @return int[]
     */
    public function getAlbumFileIdsForUser(string $userId, int $albumId): array {
        try {
            // Verify the album is owned by this user
            $stmt = $this->db->prepare('SELECT album_id FROM *PREFIX*photos_albums WHERE album_id = ? AND user = ?');
            $res = $stmt->execute([$albumId, $userId]);
            $ownRow = $res ? $res->fetch() : false;
            if ($ownRow === false) {
                // Not owned by this user; we do not attempt shared albums here
                return [];
            }

            // Fetch file ids
            $stmt2 = $this->db->prepare('SELECT file_id FROM *PREFIX*photos_albums_files WHERE album_id = ?');
            $res2 = $stmt2->execute([$albumId]);
            $rows = $res2 ? $res2->fetchAll() : [];
            $ids = [];
            foreach ($rows as $row) {
                if (isset($row['file_id'])) {
                    $ids[] = (int)$row['file_id'];
                }
            }
            return $ids;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Retrieve cluster metadata tracked by the album creator for a given user, sorted by start date.
     *
     * @param string $userId
     * @return array<int, array{album_id:int,name:string,location:?string,start_dt:?string,end_dt:?string}>
     */
    public function getTrackedClusters(string $userId): array {
        try {
            $table = $this->getTrackingTableName();
            $stmt = $this->db->prepare("SELECT album_id, name, location, start_dt, end_dt, custom_name FROM {$table} WHERE user_id = ? ORDER BY start_dt ASC, album_id ASC");
            $result = $stmt->execute([$userId]);
            $rows = $result ? $result->fetchAll() : [];
            if (!is_array($rows)) {
                return [];
            }

            return array_map(function ($row) {
                $custom = isset($row['custom_name']) && $row['custom_name'] !== null ? (string)$row['custom_name'] : null;
                if ($custom !== null && trim($custom) === '') {
                    $custom = null;
                }
                return [
                    'album_id' => isset($row['album_id']) ? (int)$row['album_id'] : 0,
                    'name' => isset($row['name']) ? (string)$row['name'] : '',
                    'location' => isset($row['location']) && $row['location'] !== null ? (string)$row['location'] : null,
                    'start_dt' => isset($row['start_dt']) ? (string)$row['start_dt'] : null,
                    'end_dt' => isset($row['end_dt']) ? (string)$row['end_dt'] : null,
                    'custom_name' => $custom,
                ];
            }, $rows);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Derive latest end datetime from tracked albums using provided images (for datetaken lookup).
     * @param string $userId
     * @param Image[] $images
     * @return \DateTimeInterface|null
     */
    public function deriveLatestEndFromTracked(string $userId, array $images): ?\DateTimeInterface {
        $albumIds = $this->getTrackedAlbumIds($userId);
        if (empty($albumIds)) {
            return null;
        }
        // Build map fileId -> datetaken
        $byId = [];
        foreach ($images as $img) {
            if (isset($img->fileid)) {
                $byId[(int)$img->fileid] = $img->datetaken;
            }
        }
        $maxTs = null;
        foreach ($albumIds as $aid) {
            $fileIds = $this->getAlbumFileIds($aid);
            foreach ($fileIds as $fid) {
                if (isset($byId[$fid])) {
                    $ts = strtotime($byId[$fid]);
                    if ($ts !== false) {
                        if ($maxTs === null || $ts > $maxTs) {
                            $maxTs = $ts;
                        }
                    }
                }
            }
        }
        if ($maxTs !== null) {
            return (new \DateTime())->setTimestamp($maxTs);
        }
        return null;
    }
}
