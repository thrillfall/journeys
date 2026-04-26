<?php
namespace OCA\Journeys\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;

class RenderedVideoLister {
    public const FOLDER_PATH = '/Documents/Journeys Movies';

    public function __construct(
        private IRootFolder $rootFolder,
    ) {}

    /**
     * @return array<int, array{name:string,path:string,fileId:int,mtime:int,size:int}>
     */
    public function listForUser(string $userId): array {
        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
        } catch (\Throwable) {
            return [];
        }

        $folder = $this->resolveMoviesFolder($userFolder);
        if ($folder === null) {
            return [];
        }

        $videos = [];
        try {
            foreach ($folder->getDirectoryListing() as $node) {
                if (!($node instanceof File)) {
                    continue;
                }
                $name = $node->getName();
                if (!str_ends_with(strtolower($name), '.mp4')) {
                    continue;
                }
                $videos[] = [
                    'name' => $name,
                    'path' => self::FOLDER_PATH . '/' . $name,
                    'fileId' => (int)$node->getId(),
                    'mtime' => (int)$node->getMTime(),
                    'size' => (int)$node->getSize(),
                ];
            }
        } catch (\Throwable) {
            return [];
        }

        usort($videos, static fn(array $a, array $b): int => $b['mtime'] <=> $a['mtime']);
        return $videos;
    }

    private function resolveMoviesFolder(Folder $userFolder): ?Folder {
        try {
            $docs = $userFolder->get('Documents');
        } catch (\Throwable) {
            return null;
        }
        if (!($docs instanceof Folder)) {
            return null;
        }
        try {
            $movies = $docs->get('Journeys Movies');
        } catch (\Throwable) {
            return null;
        }
        return $movies instanceof Folder ? $movies : null;
    }
}
