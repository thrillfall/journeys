<?php
namespace OCA\Journeys\Service;

use OCA\Photos\Album\AlbumMapper;
use OCP\IUserManager;
use OCP\Files\IRootFolder;
use OCA\Journeys\Model\Image;

class AlbumCreator {
    public const CLUSTERER_MARKER = '[clusterer]';
    private AlbumMapper $albumMapper;
    private IUserManager $userManager;
    private IRootFolder $rootFolder;

    public function __construct(AlbumMapper $albumMapper, IUserManager $userManager, IRootFolder $rootFolder) {
        $this->albumMapper = $albumMapper;
        $this->userManager = $userManager;
        $this->rootFolder = $rootFolder;
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
        // Add clusterer marker to album name
        $markedAlbumName = $albumName . ' ' . self::CLUSTERER_MARKER;
        $album = $this->albumMapper->getByName($markedAlbumName, $userId);
        if (!$album) {
            $album = $this->albumMapper->create($userId, $markedAlbumName, $location);
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
