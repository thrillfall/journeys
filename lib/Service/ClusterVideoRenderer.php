<?php
namespace OCA\Journeys\Service;

use OCP\Files\IRootFolder;
use Symfony\Component\Process\Process;

class ClusterVideoRenderer {
    public function __construct(
        private IRootFolder $rootFolder,
    ) {}

    /**
     * @param string $user
     * @param string|null $outputPath Absolute path inside server storage (optional)
     * @param float $durationPerImage Seconds per image
     * @param int $width Width of output video (height auto-calculated)
     * @param int $fps Frames per second
     * @param string $workingDir Temporary working directory containing media files
     * @param array<int, string> $files List of absolute file paths (ordered) to include
     * @param callable(string, string):void|null $outputHandler Callback to stream ffmpeg stdout/stderr
     * @param string|null $preferredFileName Suggested filename when storing into user files
     * @return array{path: string, storedInUserFiles: bool}
     */
    public function render(
        string $user,
        ?string $outputPath,
        float $durationPerImage,
        int $width,
        int $fps,
        string $workingDir,
        array $files,
        ?callable $outputHandler = null,
        ?string $preferredFileName = null,
    ): array {
        if (empty($files)) {
            throw new \InvalidArgumentException('No files provided for rendering');
        }

        $listPath = $workingDir . '/list.ffconcat';
        $tmpOut = $outputPath ?: ($workingDir . '/output.mp4');

        $this->writeConcatList($listPath, $files, $durationPerImage);

        try {
            $this->runFfmpeg($listPath, $tmpOut, $width, $fps, $outputHandler);
        } finally {
            @unlink($listPath);
        }

        if ($outputPath !== null && $outputPath !== '') {
            return [
                'path' => $tmpOut,
                'storedInUserFiles' => false,
            ];
        }

        $virtualPath = $this->persistToUserFiles($user, $tmpOut, $preferredFileName);

        return [
            'path' => $virtualPath,
            'storedInUserFiles' => true,
        ];
    }

    /**
     * @param string $listPath
     * @param array<int, string> $files
     * @param float $durationPerImage
     * @return void
     */
    private function writeConcatList(string $listPath, array $files, float $durationPerImage): void {
        $list = fopen($listPath, 'w');
        if ($list === false) {
            throw new \RuntimeException('Failed to create ffconcat list file');
        }
        fwrite($list, "ffconcat version 1.0\n");
        foreach ($files as $file) {
            fwrite($list, sprintf("file '%s'\n", str_replace("'", "'\\''", $file)));
            fwrite($list, sprintf("duration %.3f\n", max(0.1, $durationPerImage)));
        }
        fclose($list);

        // ffconcat requires last file repeated without duration
        $last = end($files);
        if ($last !== false) {
            file_put_contents($listPath, sprintf("file '%s'\n", str_replace("'", "'\\''", $last)), FILE_APPEND);
        }
    }

    private function runFfmpeg(string $listPath, string $outputPath, int $width, int $fps, ?callable $outputHandler): void {
        $vf = sprintf('scale=%d:-2,format=yuv420p', max(320, $width));
        $cmd = [
            'ffmpeg', '-y',
            '-f', 'concat', '-safe', '0', '-i', $listPath,
            '-an',
            '-r', (string)$fps,
            '-vf', $vf,
            '-pix_fmt', 'yuv420p',
            '-movflags', '+faststart',
            $outputPath,
        ];

        $process = new Process($cmd);
        $process->setTimeout(null);
        $process->setIdleTimeout(null);
        if ($outputHandler !== null) {
            $process->run($outputHandler);
        } else {
            $process->run();
        }

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('ffmpeg failed: ' . $process->getErrorOutput());
        }
    }

    private function persistToUserFiles(string $user, string $tmpOut, ?string $preferredFileName): string {
        $userFolder = $this->rootFolder->getUserFolder($user);

        try {
            $docs = $userFolder->get('Documents');
        } catch (\Throwable) {
            $docs = $userFolder->newFolder('Documents');
        }
        try {
            $movies = $docs->get('Journeys Movies');
        } catch (\Throwable) {
            $movies = $docs->newFolder('Journeys Movies');
        }

        $fileName = $this->determineFileName($preferredFileName);
        try {
            $existing = $movies->get($fileName);
            if ($existing instanceof \OCP\Files\File) {
                $existing->delete();
            }
        } catch (\Throwable) {
            // ignore
        }

        $destFile = $movies->newFile($fileName);
        $data = @file_get_contents($tmpOut);
        if ($data === false) {
            throw new \RuntimeException('Failed to read temporary video output');
        }
        $destFile->putContent($data);
        return '/Documents/Journeys Movies/' . $fileName;
    }

    private function determineFileName(?string $preferredFileName): string {
        $fallback = sprintf('Journey-%s.mp4', date('Ymd-His'));
        if ($preferredFileName === null || trim($preferredFileName) === '') {
            return $fallback;
        }

        $name = $this->sanitizeFileName($preferredFileName);
        if ($name === '') {
            return $fallback;
        }

        if (!str_ends_with(strtolower($name), '.mp4')) {
            $name .= '.mp4';
        }

        return $name;
    }

    private function sanitizeFileName(string $fileName): string {
        $fileName = str_replace(['\\', '/'], '-', $fileName);
        $fileName = preg_replace('/[^A-Za-z0-9\.\-_ ]+/', '', $fileName) ?? '';
        $fileName = trim($fileName);
        // Collapse consecutive spaces or hyphens
        $fileName = preg_replace('/\s+/', ' ', $fileName) ?? $fileName;
        return $fileName;
    }
}
