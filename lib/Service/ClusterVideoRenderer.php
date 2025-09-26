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

        $tmpOut = $outputPath ?: ($workingDir . '/output.mp4');

        $portraitFiles = $this->filterPortraitFiles($files);
        if (empty($portraitFiles)) {
            throw new \RuntimeException('No portrait images available for rendering');
        }

        $durationPerImage = max(0.5, $durationPerImage);
        $transitionDuration = min(0.8, max(0.2, $durationPerImage * 0.3));
        [$targetWidth, $targetHeight] = $this->determineOutputDimensions($width, $portraitFiles);

        $this->runFfmpeg(
            $portraitFiles,
            $tmpOut,
            $targetWidth,
            $targetHeight,
            $fps,
            $durationPerImage,
            $transitionDuration,
            $outputHandler,
        );

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

    private function runFfmpeg(
        array $files,
        string $outputPath,
        int $width,
        int $height,
        int $fps,
        float $durationPerImage,
        float $transitionDuration,
        ?callable $outputHandler,
    ): void {
        if (empty($files)) {
            throw new \RuntimeException('No files provided to ffmpeg');
        }

        $width = $this->makeEven($width);
        $height = $this->makeEven($height);

        $count = count($files);
        $holdDuration = $durationPerImage;
        $clipDuration = $holdDuration + $transitionDuration;
        $totalDurationSeconds = $count === 1
            ? $clipDuration
            : max(0.1, $holdDuration * $count + $transitionDuration);

        $cmd = ['ffmpeg', '-y', '-hide_banner', '-nostats', '-loglevel', 'error'];

        foreach ($files as $file) {
            $cmd[] = '-loop';
            $cmd[] = '1';
            $cmd[] = '-t';
            $cmd[] = $this->formatFloat($clipDuration);
            $cmd[] = '-i';
            $cmd[] = $file;
        }

        [$filterGraph, $outputLabel] = $this->buildFilterGraph(
            $count,
            $width,
            $height,
            $fps,
            $holdDuration,
            $transitionDuration,
            $clipDuration,
        );

        $cmd[] = '-filter_complex';
        $cmd[] = $filterGraph;
        $cmd[] = '-map';
        $cmd[] = $outputLabel;
        $cmd[] = '-progress';
        $cmd[] = 'pipe:1';
        $cmd[] = '-an';
        $cmd[] = '-r';
        $cmd[] = (string)$fps;
        $cmd[] = '-pix_fmt';
        $cmd[] = 'yuv420p';
        $cmd[] = '-movflags';
        $cmd[] = '+faststart';
        $cmd[] = $outputPath;

        $process = new Process($cmd);
        $process->setTimeout(null);
        $process->setIdleTimeout(null);
        $progressBuffer = '';
        $lastPercent = -1;
        $process->run(function (string $type, string $buffer) use ($outputHandler, &$progressBuffer, &$lastPercent, $totalDurationSeconds): void {
            if ($type === Process::OUT) {
                $progressBuffer .= $buffer;
                while (($newlinePos = strpos($progressBuffer, "\n")) !== false) {
                    $line = substr($progressBuffer, 0, $newlinePos);
                    $progressBuffer = substr($progressBuffer, $newlinePos + 1);
                    $trimmed = trim($line);
                    if ($trimmed === '') {
                        continue;
                    }

                    $handled = false;
                    if (str_contains($trimmed, '=')) {
                        [$key, $value] = explode('=', $trimmed, 2);
                        $key = trim($key);
                        $value = trim($value);

                        if ($key === 'out_time_ms' && $value !== '') {
                            $seconds = ((float)$value) / 1000000.0;
                            $ratio = min(1.0, max(0.0, $seconds / $totalDurationSeconds));
                            $percent = (int) floor($ratio * 100);
                            if ($percent > $lastPercent) {
                                $lastPercent = $percent;
                                if ($outputHandler !== null) {
                                    $outputHandler(Process::OUT, sprintf("Progress: %d%%\n", $percent));
                                }
                            }
                            $handled = true;
                        } elseif ($key === 'progress') {
                            if ($value === 'end') {
                                if ($outputHandler !== null) {
                                    $outputHandler(Process::OUT, "Progress: 100%\n");
                                }
                            }
                            $handled = true;
                        } elseif ($this->shouldSuppressProgressKey($key)) {
                            $handled = true;
                        }
                    }

                    // Suppress all other metrics; only percentages should surface
                }
            } elseif ($type === Process::ERR) {
                if ($outputHandler !== null && !$this->shouldSuppressWarning($buffer)) {
                    $outputHandler($type, $buffer);
                }
            }
        });

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

    private function determineOutputHeight(int $width): int {
        $height = (int) round($width * 9 / 16);
        return $this->makeEven(max(2, $height));
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

    /**
     * @param string $key
     * @return bool
     */
    private function shouldSuppressProgressKey(string $key): bool {
        return in_array($key, ['frame', 'fps', 'stream_0_0_q', 'bitrate', 'total_size', 'out_time', 'dup_frames', 'drop_frames', 'speed'], true);
    }

    /**
     * @param string $buffer
     * @return bool
     */
    private function shouldSuppressWarning(string $buffer): bool {
        $needles = [
            'deprecated pixel format used',
            'Past duration',
        ];

        foreach ($needles as $needle) {
            if (stripos($buffer, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private function buildFilterGraph(
        int $count,
        int $width,
        int $height,
        int $fps,
        float $holdDuration,
        float $transitionDuration,
        float $clipDuration,
    ): array {
        $filterParts = [];
        $frameCount = max(2, (int) round($clipDuration * $fps));

        for ($i = 0; $i < $count; $i++) {
            $motions = $this->buildKenBurnsExpressions($i, $frameCount);
            $filterParts[] = sprintf(
                '[%1$d:v]scale=%2$d:%3$d:force_original_aspect_ratio=increase,' .
                'crop=%2$d:%3$d,' .
                'zoompan=z=%4$s:x=%5$s:y=%6$s:d=%7$d:fps=%8$d:s=%2$dx%3$d,setsar=1[k%1$d]',
                $i,
                $width,
                $height,
                $motions['z'],
                $motions['x'],
                $motions['y'],
                $frameCount,
                $fps,
            );
        }

        if ($count === 1) {
            $filterParts[] = '[k0]format=yuv420p[vout]';
            return [implode(';', $filterParts), '[vout]'];
        }

        $totalDuration = $holdDuration * $count + $transitionDuration;
        $prev = 'k0';
        for ($i = 1; $i < $count; $i++) {
            $out = ($i === $count - 1) ? 'mix_last' : sprintf('mix%d', $i);
            $offset = $holdDuration * $i;
            $filterParts[] = sprintf(
                '[%1$s][k%2$d]xfade=transition=fade:duration=%3$s:offset=%4$s[%5$s]',
                $prev,
                $i,
                $this->formatFloat($transitionDuration),
                $this->formatFloat($offset),
                $out,
            );
            $prev = $out;
        }

        $finalLabel = $prev === 'k0' ? 'k0' : 'mix_last';
        $filterParts[] = sprintf('[%s]trim=duration=%s,format=yuv420p[vout]',
            $finalLabel,
            $this->formatFloat($totalDuration),
        );

        return [implode(';', $filterParts), '[vout]'];
    }

    /**
     * @param int $requestedWidth
     * @param array<int, string> $files
     * @return array{0:int,1:int}
     */
    private function determineOutputDimensions(int $requestedWidth, array $files): array {
        $longEdge = max(320, $requestedWidth);
        foreach ($files as $file) {
            $info = @getimagesize($file);
            if ($info === false || !isset($info[0], $info[1])) {
                continue;
            }

            $imgWidth = (int) $info[0];
            $imgHeight = (int) $info[1];
            if ($imgWidth <= 0 || $imgHeight <= 0) {
                continue;
            }

            $ratio = $imgWidth / $imgHeight;
            if ($ratio >= 1.0) {
                $targetWidth = $this->makeEven($longEdge);
                $targetHeight = $this->makeEven(max(2, (int) round($targetWidth / $ratio)));
                $targetHeight = max(2, $targetHeight);
            } else {
                $targetHeight = $this->makeEven($longEdge);
                $targetWidth = $this->makeEven(max(2, (int) round($targetHeight * $ratio)));
                if ($targetWidth < 320) {
                    $targetWidth = $this->makeEven(320);
                }
            }

            return [$targetWidth, $targetHeight];
        }

        $targetWidth = $this->makeEven(max(320, $requestedWidth));
        $targetHeight = $this->determineOutputHeight($targetWidth);
        return [$targetWidth, $targetHeight];
    }

    /**
     * @param array<int, string> $files
     * @return array<int, string>
     */
    private function filterPortraitFiles(array $files): array {
        $result = [];
        foreach ($files as $file) {
            $info = @getimagesize($file);
            if ($info === false || !isset($info[0], $info[1])) {
                continue;
            }

            $width = (int) $info[0];
            $height = (int) $info[1];
            if ($width > 0 && $height > 0 && $height > $width) {
                $result[] = $file;
            }
        }

        return $result;
    }

    private function buildKenBurnsExpressions(int $index, int $frameCount): array {
        $zoomStart = 1.0;
        $zoomEnd = 1.1;
        $denominator = max(1, $frameCount - 1);
        $zoomDelta = ($zoomEnd - $zoomStart) / $denominator;
        $z = sprintf("'min(%s,%s+on*%s)'",
            $this->formatFloat($zoomEnd),
            $this->formatFloat($zoomStart),
            $this->formatFloat($zoomDelta),
        );

        $progress = $denominator > 0 ? sprintf('(on/%d)', $denominator) : '0';

        switch ($index % 4) {
            case 0:
                $x = sprintf("'(iw-iw/zoom)*%s'", $progress);
                $y = "'(ih-ih/zoom)/2'";
                break;
            case 1:
                $x = sprintf("'(iw-iw/zoom)*(1-%s)'", $progress);
                $y = "'(ih-ih/zoom)/2'";
                break;
            case 2:
                $x = "'(iw-iw/zoom)/2'";
                $y = sprintf("'(ih-ih/zoom)*%s'", $progress);
                break;
            default:
                $x = "'(iw-iw/zoom)/2'";
                $y = sprintf("'(ih-ih/zoom)*(1-%s)'", $progress);
                break;
        }

        return ['z' => $z, 'x' => $x, 'y' => $y];
    }

    private function makeEven(int $value): int {
        return ($value % 2 === 0) ? $value : $value + 1;
    }

    private function formatFloat(float $value): string {
        return number_format($value, 6, '.', '');
    }
}
