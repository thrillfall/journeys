<?php
namespace OCA\Journeys\Service;

use OCA\Journeys\Model\Image;
use OCP\Files\File;
use OCP\Files\IRootFolder;

class ClusterVideoFilePreparer {
    public function __construct(
        private IRootFolder $rootFolder,
    ) {}

    /**
     * @param string $user
     * @param Image[] $images
     * @return array{workingDir: string, files: string[], copied: int}
     */
    public function prepare(string $user, array $images): array {
        $workingDir = $this->createTempDir();
        $files = [];
        $copied = 0;

        $userFolder = $this->rootFolder->getUserFolder($user);
        $index = 0;
        foreach ($images as $img) {
            if (!($img instanceof Image)) {
                continue;
            }

            $relativePath = $this->normalizePath($img->path);
            try {
                $node = $userFolder->get($relativePath);
            } catch (\Throwable) {
                continue;
            }

            if (!($node instanceof File)) {
                continue;
            }

            $mime = strtolower($node->getMimeType() ?? '');
            if (str_starts_with($mime, 'image/') === false) {
                continue;
            }

            $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION) ?: 'jpg');
            if ($extension === '' || $extension === 'jpeg') {
                $extension = 'jpg';
            }

            $destinationPath = sprintf('%s/%05d.%s', $workingDir, $index + 1, $extension);
            $sourceStream = $node->fopen('r');
            $destinationStream = fopen($destinationPath, 'w');

            if ($sourceStream !== false && $destinationStream !== false) {
                stream_copy_to_stream($sourceStream, $destinationStream);
                fclose($sourceStream);
                fclose($destinationStream);
                $files[] = $destinationPath;
                $index++;
                $copied++;
            } else {
                if (is_resource($sourceStream)) {
                    fclose($sourceStream);
                }
                if (is_resource($destinationStream)) {
                    fclose($destinationStream);
                }
                @unlink($destinationPath);
            }
        }

        return [
            'workingDir' => $workingDir,
            'files' => $files,
            'copied' => $copied,
        ];
    }

    public function cleanup(string $workingDir): void {
        foreach (glob($workingDir . '/*') as $file) {
            @unlink($file);
        }
        @rmdir($workingDir);
    }

    private function createTempDir(): string {
        $workingDir = sys_get_temp_dir() . '/journeys_video_' . uniqid('', true);
        if (!@mkdir($workingDir, 0770, true) && !is_dir($workingDir)) {
            throw new \RuntimeException('Failed to create temp directory');
        }
        return $workingDir;
    }

    private function normalizePath(string $path): string {
        return str_starts_with($path, 'files/') ? substr($path, 6) : $path;
    }
}
