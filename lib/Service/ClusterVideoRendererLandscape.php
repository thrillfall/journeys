<?php
namespace OCA\Journeys\Service;

use OCP\Files\IRootFolder;
use Symfony\Component\Process\Process;

class ClusterVideoRendererLandscape {
    public function __construct(
        private IRootFolder $rootFolder,
        private ClusterVideoMusicProvider $musicProvider,
    ) {}

    /**
     * @param string $user
     * @param string|null $outputPath Absolute path inside server storage (optional)
     * @param float $durationPerImage Seconds per image
     * @param int $width Output width (height auto to 16:9)
     * @param int $fps Frames per second
     * @param string $workingDir Temporary working directory containing media files
     * @param array<int, string> $files Absolute file paths (ordered)
     * @param callable(string, string):void|null $outputHandler
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

        // Keep only landscape images (width >= height). Skip all portraits.
        $files = $this->filterLandscapeFiles($files);
        if (empty($files)) {
            throw new \RuntimeException('No landscape images available to render');
        }

        $tmpOut = $outputPath ?: ($workingDir . '/journey_landscape.mp4');
        $durationPerImage = max(0.5, $durationPerImage);
        $width = $this->makeEven(max(320, $width));
        $height = $this->determineOutputHeight($width); // 16:9, even

        $audioTrack = $this->musicProvider->pickRandomTrack();

        $cmd = ['ffmpeg', '-y', '-hide_banner', '-nostats', '-loglevel', 'error'];

        // Mirror portrait timing: hold and transition
        $holdDuration = max(0.5, $durationPerImage);
        $transitionDuration = min(0.8, max(0.2, $holdDuration * 0.3));
        $clipDuration = $holdDuration + $transitionDuration; // input lifespan to allow xfade overlap
        foreach ($files as $f) {
            $cmd[] = '-loop';
            $cmd[] = '1';
            $cmd[] = '-t';
            $cmd[] = $this->formatFloat($clipDuration);
            $cmd[] = '-i';
            $cmd[] = $f;
        }

        $audioInputIndex = null;
        if ($audioTrack !== null && is_file($audioTrack)) {
            $cmd[] = '-stream_loop';
            $cmd[] = '-1';
            $cmd[] = '-i';
            $cmd[] = $audioTrack;
            $audioInputIndex = count($files);
        }

        // Build filter graph: per-image Ken Burns on 16:9 canvas, then xfade chain
        $parts = [];
        $prepLabels = [];
        $frameCount = max(2, (int) round($clipDuration * $fps));
        for ($i = 0; $i < count($files); $i++) {
            $label = sprintf('kseg%d', $i);
            $motion = $this->buildKenBurnsExpressions($i, $frameCount);
            // Prepare 16:9 canvas first, then apply zoompan for motion variety
            $parts[] = sprintf(
                '[%1$d:v]scale=%2$d:%3$d:force_original_aspect_ratio=increase,' .
                'crop=%2$d:%3$d,' .
                'zoompan=z=%4$s:x=%5$s:y=%6$s:d=%7$d:fps=%8$d:s=%2$dx%3$d,setsar=1,setpts=PTS-STARTPTS[%9$s]',
                $i,
                $width,
                $height,
                $motion['z'],
                $motion['x'],
                $motion['y'],
                $frameCount,
                $fps,
                $label,
            );
            $prepLabels[] = '[' . $label . ']';
        }
        if (count($prepLabels) === 1) {
            $parts[] = sprintf('%sformat=yuv420p[vout]', $prepLabels[0]);
        } else {
            // xfade chain with fade transitions at holdDuration offsets
            $prev = 'kseg0';
            for ($i = 1; $i < count($prepLabels); $i++) {
                $out = ($i === count($prepLabels) - 1) ? 'mix_last' : sprintf('mix%d', $i);
                $parts[] = sprintf(
                    '[%1$s][%2$s]xfade=transition=fade:duration=%3$s:offset=%4$s[%5$s]',
                    $prev,
                    sprintf('kseg%d', $i),
                    $this->formatFloat($transitionDuration),
                    $this->formatFloat($holdDuration * $i),
                    $out,
                );
                $prev = $out;
            }
            $final = ($prev === 'kseg0') ? 'kseg0' : 'mix_last';
            $totalDuration = $holdDuration * count($prepLabels) + $transitionDuration;
            $parts[] = sprintf('[%s]trim=duration=%s,format=yuv420p[vout]', $final, $this->formatFloat($totalDuration));
        }

        $cmd[] = '-filter_complex';
        $cmd[] = implode(';', $parts);
        $cmd[] = '-map';
        $cmd[] = '[vout]';
        if ($audioInputIndex !== null) {
            $cmd[] = '-map';
            $cmd[] = sprintf('%d:a:0', $audioInputIndex);
            // Gentle fade-out at the end; match xfade total video duration
            $totalDurationSeconds = $holdDuration * count($files) + $transitionDuration;
            $fadeDur = min(5.0, max(0.5, $totalDurationSeconds * 0.08));
            $fadeStart = max(0.0, $totalDurationSeconds - $fadeDur);
            $cmd[] = '-filter:a';
            $cmd[] = sprintf('atrim=0:%1$s,asetpts=PTS-STARTPTS,afade=t=out:st=%2$s:d=%3$s',
                $this->formatFloat($totalDurationSeconds),
                $this->formatFloat($fadeStart),
                $this->formatFloat($fadeDur)
            );
            $cmd[] = '-shortest';
            $cmd[] = '-c:a';
            $cmd[] = 'aac';
            $cmd[] = '-b:a';
            $cmd[] = '192k';
        } else {
            $cmd[] = '-an';
        }
        $cmd[] = '-progress';
        $cmd[] = 'pipe:1';
        $cmd[] = '-r';
        $cmd[] = (string)$fps;
        $cmd[] = '-pix_fmt';
        $cmd[] = 'yuv420p';
        $cmd[] = '-movflags';
        $cmd[] = '+faststart';
        $cmd[] = $tmpOut;

        $process = new Process($cmd);
        $process->setTimeout(null);
        $process->setIdleTimeout(null);
        $totalDurationSeconds = $holdDuration * count($files) + $transitionDuration;
        $progressBuffer = '';
        $lastPercent = -1;
        $process->run(function (string $type, string $buffer) use ($outputHandler, &$progressBuffer, &$lastPercent, $totalDurationSeconds): void {
            if ($type === Process::OUT) {
                $progressBuffer .= $buffer;
                // process line-buffered progress output
                while (($newlinePos = strpos($progressBuffer, "\n")) !== false) {
                    $line = substr($progressBuffer, 0, $newlinePos);
                    $progressBuffer = substr($progressBuffer, $newlinePos + 1);
                    $trimmed = trim($line);
                    if ($trimmed === '') { continue; }
                    if (!str_contains($trimmed, '=')) { continue; }
                    [$key, $value] = explode('=', $trimmed, 2);
                    $key = trim($key); $value = trim($value);
                    if ($key === 'out_time_ms' && $value !== '') {
                        $seconds = ((float)$value) / 1000000.0;
                        $ratio = min(1.0, max(0.0, $seconds / max(0.1, $totalDurationSeconds)));
                        $percent = (int) floor($ratio * 100);
                        if ($percent > $lastPercent) {
                            $lastPercent = $percent;
                            if ($outputHandler !== null) {
                                $outputHandler(Process::OUT, sprintf("Progress: %d%%\n", $percent));
                            }
                        }
                    } elseif ($key === 'progress' && $value === 'end') {
                        if ($outputHandler !== null) {
                            $outputHandler(Process::OUT, "Progress: 100%\n");
                        }
                    }
                }
            } elseif ($type === Process::ERR) {
                if ($outputHandler !== null) {
                    $outputHandler($type, $buffer);
                }
            }
        });

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('ffmpeg failed: ' . $process->getErrorOutput());
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
     * @param array<int,string> $files
     * @return array<int,string>
     */
    private function filterLandscapeFiles(array $files): array {
        $out = [];
        foreach ($files as $f) {
            [$w, $h] = $this->orientedImageSize($f);
            if ($w > 0 && $h > 0 && $w >= $h) {
                $out[] = $f;
            }
        }
        return $out;
    }

    /**
     * @return array{0:int,1:int}
     */
    private function safeImageSize(string $path): array {
        $w = 0; $h = 0;
        try {
            $info = @getimagesize($path);
            if (is_array($info) && isset($info[0], $info[1])) {
                $w = (int)$info[0];
                $h = (int)$info[1];
            }
        } catch (\Throwable) {}
        return [$w, $h];
    }

    /**
     * Compute oriented dimensions using EXIF Orientation when present (for JPEGs).
     * @return array{0:int,1:int}
     */
    private function orientedImageSize(string $path): array {
        [$w, $h] = $this->safeImageSize($path);
        if ($w <= 0 || $h <= 0) { return [$w, $h]; }
        // Only JPEGs typically have EXIF orientation that matters for rotation
        $lower = strtolower($path);
        if (!str_ends_with($lower, '.jpg') && !str_ends_with($lower, '.jpeg')) {
            return [$w, $h];
        }
        try {
            if (function_exists('exif_read_data')) {
                $exif = @exif_read_data($path);
                if (is_array($exif) && isset($exif['Orientation'])) {
                    $orientation = (int)$exif['Orientation'];
                    // 5,6,7,8 correspond to 90/270 degree rotations
                    if (in_array($orientation, [5, 6, 7, 8], true)) {
                        return [$h, $w];
                    }
                }
            }
        } catch (\Throwable) {}
        return [$w, $h];
    }

    private function buildKenBurnsExpressions(int $index, int $frameCount): array {
        $zoomStart = 1.0;
        $zoomEnd = 1.1;
        $den = max(1, $frameCount - 1);
        $zoomDelta = ($zoomEnd - $zoomStart) / $den;
        $z = sprintf("'min(%s,%s+on*%s)'",
            $this->formatFloat($zoomEnd),
            $this->formatFloat($zoomStart),
            $this->formatFloat($zoomDelta),
        );

        $progress = $den > 0 ? sprintf('(on/%d)', $den) : '0';
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

    private function persistToUserFiles(string $user, string $tmpOut, ?string $preferredFileName): string {
        $userFolder = $this->rootFolder->getUserFolder($user);
        try { $docs = $userFolder->get('Documents'); } catch (\Throwable) { $docs = $userFolder->newFolder('Documents'); }
        try { $movies = $docs->get('Journeys Movies'); } catch (\Throwable) { $movies = $docs->newFolder('Journeys Movies'); }

        $fileName = $this->determineFileName($preferredFileName);
        try { $existing = $movies->get($fileName); if ($existing instanceof \OCP\Files\File) { $existing->delete(); } } catch (\Throwable) {}
        $destFile = $movies->newFile($fileName);
        $data = @file_get_contents($tmpOut);
        if ($data === false) { throw new \RuntimeException('Failed to read temporary video output'); }
        $destFile->putContent($data);
        return '/Documents/Journeys Movies/' . $fileName;
    }

    private function determineFileName(?string $preferredFileName): string {
        $fallback = sprintf('Journey-Landscape-%s.mp4', date('Ymd-His'));
        if ($preferredFileName === null || trim($preferredFileName) === '') {
            return $fallback;
        }
        $name = $this->sanitizeFileName($preferredFileName);
        if ($name === '') { return $fallback; }
        if (!str_ends_with(strtolower($name), '.mp4')) { $name .= '.mp4'; }
        return $name;
    }

    private function sanitizeFileName(string $fileName): string {
        $fileName = str_replace(['\\', '/'], '-', $fileName);
        $fileName = preg_replace('/[^A-Za-z0-9\.\-_ ]+/', '', $fileName) ?? '';
        $fileName = trim($fileName);
        $fileName = preg_replace('/\s+/', ' ', $fileName) ?? $fileName;
        return $fileName;
    }

    private function determineOutputHeight(int $width): int {
        $height = (int) round($width * 9 / 16);
        return $this->makeEven(max(2, $height));
    }

    private function makeEven(int $value): int { return ($value % 2 === 0) ? $value : $value + 1; }
    private function formatFloat(float $v): string { return number_format($v, 6, '.', ''); }
}
