<?php
namespace OCA\Journeys\Service;

use OCA\Photos\Album\AlbumMapper;
use OCP\IUserManager;
use OCP\Files\IRootFolder;
use OCP\SystemTag\ISystemTagManager;
use OCP\IDBConnection;
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

    private const JOURNEYS_TAG = 'journeys-album';

    public function __construct(AlbumMapper $albumMapper, IUserManager $userManager, IRootFolder $rootFolder, ISystemTagManager $systemTagManager, IDBConnection $db) {
        $this->albumMapper = $albumMapper;
        $this->userManager = $userManager;
        $this->rootFolder = $rootFolder;
        $this->systemTagManager = $systemTagManager;
        $this->db = $db;
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
    public function createAlbumWithImages(string $userId, string $albumName, array $images, string $location = '', ?\DateTime $dtStart = null, ?\DateTime $dtEnd = null): void {
        $album = $this->albumMapper->getByName($albumName, $userId);
        if (!$album) {
            $album = $this->albumMapper->create($userId, $albumName, $location);
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
        try {
            $this->trackClusterAlbum($userId, (int)$album->getId(), $album->getName(), $location, $dtStart, $dtEnd);
        } catch (\Throwable $e) {
            // best-effort tracking
        }
        // Assign SystemTag to album folder (in addition to postfix logic)
        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
            $albumFolder = $userFolder->get($album->getName());
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
     * Track a clusterer-created album for a user.
     */
    private function trackClusterAlbum(string $userId, int $albumId, string $name, string $location, ?\DateTime $dtStart, ?\DateTime $dtEnd): void {
        $table = $this->getTrackingTableName();
        // Delete existing row (if any) then insert, to avoid DB-specific upsert syntax
        $del = $this->db->prepare("DELETE FROM {$table} WHERE user_id = ? AND album_id = ?");
        $del->execute([$userId, $albumId]);
        $ins = $this->db->prepare("INSERT INTO {$table} (user_id, album_id, name, location, start_dt, end_dt) VALUES (?, ?, ?, ?, ?, ?)");
        $ins->execute([
            $userId,
            $albumId,
            $name,
            $location,
            $dtStart ? $dtStart->format('Y-m-d H:i:s') : null,
            $dtEnd ? $dtEnd->format('Y-m-d H:i:s') : null,
        ]);
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
}
