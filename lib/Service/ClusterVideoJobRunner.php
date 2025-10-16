<?php
namespace OCA\Journeys\Service;

use OCA\Journeys\Exception\ClusterNotFoundException;
use OCA\Journeys\Exception\NoImagesFoundException;
use OCA\Journeys\Model\ClusterVideoSelection;

class ClusterVideoJobRunner {
    public function __construct(
        private ClusterVideoImageProvider $imageProvider,
        private ClusterVideoFilePreparer $filePreparer,
        private ClusterVideoRenderer $videoRenderer,
        private ClusterVideoRendererLandscape $videoRendererLandscape,
    ) {}

    /**
     * Render a cluster video for a Photos album id.
     *
     * @param string $user User id
     * @param int $albumId Photos album id
     * @param int $minGapSeconds Minimum seconds between selected images
     * @param float $durationPerImage Seconds per image in the final video
     * @param int $width Output width
     * @param int $fps Frames per second
     * @param int $maxImages Maximum number of images to include
     * @param string|null $outputPath Optional absolute output path inside the server
     * @param callable(string,string):void|null $outputHandler Optional ffmpeg output handler
     *
     * @return array{path:string,storedInUserFiles:bool,imageCount:int,clusterName:string,clusterIndex:int}
     *
     * @throws NoImagesFoundException
     * @throws ClusterNotFoundException
     */
    public function renderForAlbum(
        string $user,
        int $albumId,
        int $minGapSeconds = 5,
        float $durationPerImage = 2.5,
        int $width = 1920,
        int $fps = 30,
        int $maxImages = 80,
        ?string $outputPath = null,
        ?callable $outputHandler = null,
    ): array {
        $selection = $this->imageProvider->getSelectedImagesForAlbumId($user, $albumId, $minGapSeconds, $maxImages);

        if (empty($selection->selectedImages)) {
            throw new NoImagesFoundException('No suitable images found for this cluster.');
        }

        $preparation = $this->filePreparer->prepare($user, $selection->selectedImages);
        $workingDir = $preparation['workingDir'] ?? null;
        $filePaths = $preparation['files'] ?? [];
        $copied = (int)($preparation['copied'] ?? count($filePaths));

        if ($workingDir === null || $workingDir === '' || empty($filePaths) || $copied === 0) {
            if ($workingDir !== null && $workingDir !== '') {
                $this->filePreparer->cleanup($workingDir);
            }
            throw new NoImagesFoundException('No readable files to render.');
        }

        $preferredFileName = $this->buildPreferredFileName($selection);

        try {
            $result = $this->videoRenderer->render(
                $user,
                $outputPath,
                $durationPerImage,
                $width,
                $fps,
                $workingDir,
                $filePaths,
                $outputHandler,
                $preferredFileName,
            );
        } finally {
            $this->filePreparer->cleanup($workingDir);
        }

        if (!is_array($result) || !isset($result['path'])) {
            throw new \RuntimeException('Video rendering did not return a path.');
        }

        return [
            'path' => (string)$result['path'],
            'storedInUserFiles' => (bool)($result['storedInUserFiles'] ?? false),
            'imageCount' => $copied,
            'clusterName' => $selection->clusterName,
            'clusterIndex' => $selection->clusterIndex,
        ];
    }

    /**
     * @return array{path:string,storedInUserFiles:bool,imageCount:int,clusterName:string,clusterIndex:int}
     */
    public function renderForAlbumLandscape(
        string $user,
        int $albumId,
        int $minGapSeconds = 5,
        float $durationPerImage = 2.5,
        int $width = 1920,
        int $fps = 30,
        int $maxImages = 80,
        ?string $outputPath = null,
        ?callable $outputHandler = null,
    ): array {
        $selection = $this->imageProvider->getSelectedImagesForAlbumId($user, $albumId, $minGapSeconds, $maxImages);

        if (empty($selection->selectedImages)) {
            throw new NoImagesFoundException('No suitable images found for this cluster.');
        }

        $preparation = $this->filePreparer->prepare($user, $selection->selectedImages);
        $workingDir = $preparation['workingDir'] ?? null;
        $filePaths = $preparation['files'] ?? [];
        $copied = (int)($preparation['copied'] ?? count($filePaths));

        if ($workingDir === null || $workingDir === '' || empty($filePaths) || $copied === 0) {
            if ($workingDir !== null && $workingDir !== '') {
                $this->filePreparer->cleanup($workingDir);
            }
            throw new NoImagesFoundException('No readable files to render.');
        }

        $preferredFileName = $this->buildPreferredFileName($selection) . ' (landscape)';

        try {
            $result = $this->videoRendererLandscape->render(
                $user,
                $outputPath,
                $durationPerImage,
                $width,
                $fps,
                $workingDir,
                $filePaths,
                $outputHandler,
                $preferredFileName,
            );
        } finally {
            $this->filePreparer->cleanup($workingDir);
        }

        if (!is_array($result) || !isset($result['path'])) {
            throw new \RuntimeException('Video rendering did not return a path.');
        }

        return [
            'path' => (string)$result['path'],
            'storedInUserFiles' => (bool)($result['storedInUserFiles'] ?? false),
            'imageCount' => $copied,
            'clusterName' => $selection->clusterName,
            'clusterIndex' => $selection->clusterIndex,
        ];
    }

    private function buildPreferredFileName(ClusterVideoSelection $selection): string {
        $clusterId = $selection->clusterIndex + 1;
        $clusterName = trim($selection->clusterName);
        if ($clusterName === '') {
            $clusterName = 'Untitled';
        }

        return sprintf('%02d - %s', $clusterId, $clusterName);
    }
}
