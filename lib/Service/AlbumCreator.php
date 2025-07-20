<?php
namespace OCA\Journeys\Service;

use OCA\Photos\Album\AlbumMapper;
use OCP\IUserManager;
use OCP\Files\IRootFolder;
use OCP\SystemTag\ISystemTagManager;
use OCA\Journeys\Model\Image;

class AlbumCreator {
    /**
     * Delete all albums created by the clusterer for a user (marked with CLUSTERER_MARKER).
     *
     * @param string $userId
     * @return int Number of albums deleted
     */
    public function purgeClusterAlbums(string $userId): int {
        $deleted = 0;
        $albums = $this->albumMapper->getForUser($userId);
        $deletedAlbumIds = [];
        // 1. Delete albums with the journeys SystemTag
        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
            $tags = $this->systemTagManager->getTagsByName(self::JOURNEYS_TAG);
            if (!empty($tags)) {
                $journeysClusterTag = array_values($tags)[0];
                foreach ($albums as $album) {
                    try {
                        $albumFolder = $userFolder->get($album->getName());
                        $objectTags = $this->systemTagManager->getTagsForObject($albumFolder);
                        foreach ($objectTags as $objectTag) {
                            if ($objectTag->getId() === $journeysClusterTag->getId()) {
                                $this->albumMapper->delete($album->getId());
                                $deletedAlbumIds[] = $album->getId();
                                $deleted++;
                                break;
                            }
                        }
                    } catch (\Throwable $e) {
                        // Ignore missing folders or errors
                    }
                }
            }
        } catch (\Throwable $e) {
            // Tagging is best-effort; ignore errors
        }
        // 2. Fallback: delete any remaining albums with the postfix marker
        foreach ($albums as $album) {
            if (in_array($album->getId(), $deletedAlbumIds)) {
                continue;
            }
            if (strpos($album->getTitle(), self::CLUSTERER_MARKER) !== false) {
                $this->albumMapper->delete($album->getId());
                $deleted++;
            }
        }
        return $deleted;
    }
    public const CLUSTERER_MARKER = '[clusterer]';
    private AlbumMapper $albumMapper;
    private IRootFolder $rootFolder;
    private ISystemTagManager $systemTagManager;

    private const JOURNEYS_TAG = 'journeys-album';

    public function __construct(AlbumMapper $albumMapper, IUserManager $userManager, IRootFolder $rootFolder, ISystemTagManager $systemTagManager) {
        $this->albumMapper = $albumMapper;
        $this->userManager = $userManager;
        $this->rootFolder = $rootFolder;
        $this->systemTagManager = $systemTagManager;
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
    public function createAlbumWithImages(string $userId, string $albumName, array $images, string $location = ''): void {
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
}
