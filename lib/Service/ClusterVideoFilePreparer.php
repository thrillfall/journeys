<?php
namespace OCA\Journeys\Service;

use OCA\Journeys\Model\Image;
use OCP\Files\File;
use OCP\Files\Folder;
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

            $resolved = $this->resolveFileNode($userFolder, $img);
            if ($resolved === null) {
                continue;
            }

            /** @var File $node */
            [$node, $relativePath] = $resolved;

            $mime = strtolower($node->getMimeType() ?? '');
            if (str_starts_with($mime, 'image/') === false) {
                continue;
            }

            $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION) ?: pathinfo($node->getName(), PATHINFO_EXTENSION) ?: 'jpg');
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
                $this->maybeExtractGcamTrailer($destinationPath);
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

    /**
     * @return array{0:File,1:string}|null
     */
    private function resolveFileNode(Folder $userFolder, Image $img): ?array {
        $fileId = $img->fileid ?? null;
        if ($fileId === null) {
            return null;
        }

        $userPrefix = rtrim($userFolder->getPath(), '/');
        $candidates = [];

        try {
            $candidates = $userFolder->getById($fileId);
        } catch (\Throwable) {
            $candidates = [];
        }

        if (empty($candidates)) {
            try {
                $candidates = $this->rootFolder->getById($fileId);
            } catch (\Throwable) {
                return null;
            }
        }

        foreach ($candidates as $candidate) {
            if (!($candidate instanceof File)) {
                continue;
            }

            $candidatePath = $candidate->getPath();
            if ($candidatePath === '' || !str_starts_with($candidatePath, $userPrefix . '/')) {
                continue;
            }

            $relative = substr($candidatePath, strlen($userPrefix) + 1);
            $relative = $relative !== false ? $relative : '';
            $relative = $relative !== '' ? $this->normalizePath($relative) : $this->normalizePath($candidate->getName());

            if ($relative === '') {
                $relative = $candidate->getName();
            }

            return [$candidate, $relative];
        }

        return null;
    }

    private function maybeExtractGcamTrailer(string $imagePath): void {
        $lower = strtolower($imagePath);
        if (!str_ends_with($lower, '.jpg') && !str_ends_with($lower, '.jpeg')) {
            return;
        }
        $outPath = substr($imagePath, 0, strrpos($imagePath, '.')) . '.mp4';
        if (is_file($outPath)) {
            return;
        }
        $size = @filesize($imagePath);
        if (!is_int($size) || $size <= 0) {
            return;
        }
        // Preferred: use MicroVideoOffset from EXIF if available (video length at end of file)
        try {
            if (function_exists('exif_read_data')) {
                $exif = @exif_read_data($imagePath, null, true);
                $offsetLen = null;
                if (is_array($exif)) {
                    // Try multiple sections just in case
                    foreach ($exif as $section) {
                        if (is_array($section) && isset($section['MicroVideoOffset']) && is_numeric($section['MicroVideoOffset'])) {
                            $offsetLen = (int)$section['MicroVideoOffset'];
                            break;
                        }
                    }
                }
                if (is_int($offsetLen) && $offsetLen > 0 && $offsetLen < $size) {
                    $start = $size - $offsetLen;
                    $src = @fopen($imagePath, 'rb');
                    $dst = @fopen($outPath, 'wb');
                    if ($src !== false && $dst !== false && @fseek($src, $start) === 0) {
                        $remaining = $offsetLen;
                        $chunk = 1024 * 1024;
                        while ($remaining > 0) {
                            $n = min($chunk, $remaining);
                            $data = @fread($src, $n);
                            if (!is_string($data) || $data === '') { break; }
                            @fwrite($dst, $data);
                            $remaining -= strlen($data);
                        }
                        fclose($src);
                        fclose($dst);
                        if (@filesize($outPath) > 0) {
                            return; // success
                        }
                        @unlink($outPath);
                    } else {
                        if (is_resource($src)) { fclose($src); }
                        if (is_resource($dst)) { fclose($dst); }
                        @unlink($outPath);
                    }
                }
            }
        } catch (\Throwable) {}

        $read = min(32 * 1024 * 1024, $size);
        $fh = @fopen($imagePath, 'rb');
        if ($fh === false) {
            return;
        }
        try {
            if (@fseek($fh, $size - $read) !== 0) {
                return;
            }
            $buf = @fread($fh, $read);
            if (!is_string($buf) || $buf === '') {
                return;
            }
            // Find the last occurrence of 'ftyp' which should belong to the MP4 header
            $pos = strrpos($buf, 'ftyp');
            if ($pos === false) {
                return;
            }
            // MP4 starts 4 bytes before 'ftyp' (box size header)
            $candidateOffset = ($size - $read) + $pos - 4;
            if ($candidateOffset < 0) {
                return;
            }
            // Sanity check the ftyp box fields
            $brand = substr($buf, $pos + 4, 8) ?: '';
            $brandOk = str_contains($brand, 'isom') || str_contains($brand, 'mp42') || str_contains($brand, 'iso5') || str_contains($brand, 'avc1');
            if (!$brandOk) {
                return;
            }
            $globalOffset = $candidateOffset;
            $src = @fopen($imagePath, 'rb');
            $dst = @fopen($outPath, 'wb');
            if ($src === false || $dst === false) {
                if (is_resource($src)) { fclose($src); }
                if (is_resource($dst)) { fclose($dst); }
                @unlink($outPath);
                return;
            }
            if (@fseek($src, $globalOffset) !== 0) {
                fclose($src);
                fclose($dst);
                @unlink($outPath);
                return;
            }
            $remaining = $size - $globalOffset;
            $chunk = 1024 * 1024;
            while ($remaining > 0) {
                $n = min($chunk, $remaining);
                $data = @fread($src, $n);
                if (!is_string($data) || $data === '') { break; }
                @fwrite($dst, $data);
                $remaining -= strlen($data);
            }
            fclose($src);
            fclose($dst);
            if (!is_file($outPath) || @filesize($outPath) <= 0) {
                @unlink($outPath);
            }
        } finally {
            fclose($fh);
        }
    }
}
