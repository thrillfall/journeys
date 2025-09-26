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
     * Delete all albums for a user (regardless of marker/tag).
     *
     * @param string $userId
     * @return int Number of albums deleted
     */
    public function purgeAllAlbums(string $userId): int {
        $deleted = 0;
        $albums = $this->albumMapper->getForUser($userId);
        foreach ($albums as $album) {
            try {
                $this->albumMapper->delete($album->getId());
                $deleted++;
            } catch (\Throwable $e) {
                // Ignore and continue
            }
        }
        return $deleted;
    }

    /**
     * Delete all albums created by the clusterer for a user (marked with CLUSTERER_MARKER).
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
    public function createAlbumWithImages(string $userId, string $albumName, array $images, string $location = '', ?\DateTime $dtStart = null, ?\DateTime $dtEnd = null): ?int {
        // Always attempt to create a new album; do not reuse by name to avoid collisions with manually created albums
        try {
            $album = $this->albumMapper->create($userId, $albumName, $location);
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
            $fileId = $this->getFileIdForImage($userId, $image);
            if ($fileId !== null) {
                try {
                    $this->albumMapper->addFile($album->getId(), $fileId, $userId);
                } catch (\Throwable $e) {
                    // Ignore if image is already in album or other non-fatal errors
                }
            }
        }
        // Track this album as clusterer-created for reliable purge and incremental boundary detection
        $this->trackClusterAlbum($userId, (int)$album->getId(), $album->getTitle(), $location, $dtStart, $dtEnd);
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
    private function trackClusterAlbum(string $userId, int $albumId, string $name, string $location, ?\DateTime $dtStart, ?\DateTime $dtEnd): void {
        $table = $this->getTrackingTableName();
        // Delete existing row (if any) then insert, to avoid DB-specific upsert syntax
        $del = $this->db->prepare("DELETE FROM {$table} WHERE user_id = ? AND album_id = ?");
        $delResult = $del->execute([$userId, $albumId]);
        if ($delResult === false) {
            throw new \RuntimeException('Failed to delete existing tracking row for cluster album');
        }
        $ins = $this->db->prepare("INSERT INTO {$table} (user_id, album_id, name, location, start_dt, end_dt) VALUES (?, ?, ?, ?, ?, ?)");
        $insResult = $ins->execute([
            $userId,
            $albumId,
            $name,
            $location,
            $dtStart ? $dtStart->format('Y-m-d H:i:s') : null,
            $dtEnd ? $dtEnd->format('Y-m-d H:i:s') : null,
        ]);
        if ($insResult === false) {
            throw new \RuntimeException('Failed to insert tracking row for cluster album');
        }
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
            $stmt = $this->db->prepare("SELECT album_id, name, location, start_dt, end_dt FROM {$table} WHERE user_id = ? ORDER BY start_dt ASC, album_id ASC");
            $result = $stmt->execute([$userId]);
            $rows = $result ? $result->fetchAll() : [];
            if (!is_array($rows)) {
                return [];
            }

            return array_map(function ($row) {
                return [
                    'album_id' => isset($row['album_id']) ? (int)$row['album_id'] : 0,
                    'name' => isset($row['name']) ? (string)$row['name'] : '',
                    'location' => isset($row['location']) && $row['location'] !== null ? (string)$row['location'] : null,
                    'start_dt' => isset($row['start_dt']) ? (string)$row['start_dt'] : null,
                    'end_dt' => isset($row['end_dt']) ? (string)$row['end_dt'] : null,
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
