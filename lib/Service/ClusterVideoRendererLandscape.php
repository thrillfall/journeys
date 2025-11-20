<?php
namespace OCA\Journeys\Service;

use OCP\Files\IRootFolder;
use Symfony\Component\Process\Process;

class ClusterVideoRendererLandscape {
    public function __construct(
        private IRootFolder $rootFolder,
        private ClusterVideoMusicProvider $musicProvider,
        private VideoTitleFormatter $titleFormatter,
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
     * @param bool $includeMotion Replace landscape images with GCam motion videos when available
     * @param string|null $albumName Album name for title overlay (null to disable title)
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
        bool $includeMotion = true,
        bool $verbose = false,
        ?string $albumName = null,
    ): array {
        if (empty($files)) {
            throw new \InvalidArgumentException('No files provided for rendering');
        }

        // Keep only landscape images (width >= height). Skip all portraits.
        $files = $this->filterLandscapeFiles($files);
        if (empty($files)) {
            throw new \RuntimeException('No landscape images available to render');
        }

        // Replace landscape images with GCam motion videos when available (if enabled)
        if ($includeMotion) {
            $files = $this->preferVideoWhereAvailable($files);
        }

        $tmpOut = $outputPath ?: ($workingDir . '/journey_landscape.mp4');
        $durationPerImage = max(0.5, $durationPerImage);
        $width = $this->makeEven(max(320, $width));
        $height = $this->determineOutputHeight($width); // 16:9, even

        $audioTrack = $this->musicProvider->pickRandomTrack();

        $logLevel = $verbose ? 'info' : 'error';
        $cmd = ['ffmpeg', '-y', '-hide_banner', '-loglevel', $logLevel];
        if (!$verbose) {
            $cmd[] = '-nostats';
        }

        // Mirror portrait timing: hold and transition
        $holdDuration = max(0.5, $durationPerImage);
        $transitionDuration = min(0.8, max(0.2, $holdDuration * 0.3));
        $clipDuration = $holdDuration + $transitionDuration; // input lifespan to allow xfade overlap

        // Build segments to distinguish images from videos
        $segments = [];
        foreach ($files as $f) {
            $isVideo = $this->isVideoFile($f);
            $segments[] = ['type' => $isVideo ? 'video' : 'image', 'file' => $f];
        }

        // Register inputs based on type
        foreach ($segments as $seg) {
            if ($seg['type'] === 'image') {
                $cmd[] = '-loop';
                $cmd[] = '1';
                $cmd[] = '-t';
                $cmd[] = $this->formatFloat($clipDuration);
                $cmd[] = '-i';
                $cmd[] = $seg['file'];
            } else {
                // Video input (no loop needed)
                $cmd[] = '-i';
                $cmd[] = $seg['file'];
            }
        }

        $audioInputIndex = null;
        if ($audioTrack !== null && is_file($audioTrack)) {
            $cmd[] = '-stream_loop';
            $cmd[] = '-1';
            $cmd[] = '-i';
            $cmd[] = $audioTrack;
            $audioInputIndex = count($segments);
        }

        // Build filter graph: per-segment Ken Burns (for images) or time-stretch (for videos) on 16:9 canvas, then xfade chain
        $parts = [];
        $prepLabels = [];
        $frameCount = max(2, (int) round($clipDuration * $fps));
        for ($i = 0; $i < count($segments); $i++) {
            $label = sprintf('kseg%d', $i);
            $seg = $segments[$i];

            if ($seg['type'] === 'image') {
                $motion = $this->buildKenBurnsExpressions($i, $frameCount);
                // Prepare 16:9 canvas first, then apply zoompan for motion variety
                $baseLabel = sprintf('kseg_base%d', $i);
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
                    $baseLabel,
                );

                // Add album name overlay to first still image segment only
                if ($i === 0 && $albumName !== null && $albumName !== '') {
                    // Format album name for video overlay (calculates font size, wraps text, escapes for FFmpeg)
                    $formatted = $this->titleFormatter->formatForVideo($albumName, $width, 0.8);
                    // Build FFmpeg drawtext filter (4 seconds: fade in 0.5s, visible 3s, fade out 0.5s)
                    $parts[] = $this->titleFormatter->buildDrawtextFilter(
                        $baseLabel,
                        $label,
                        $formatted['text'],
                        $formatted['fontSize'],
                        4.0,
                        3 // shadow offset for landscape
                    );
                } else {
                    // No text, just pass through
                    $parts[] = sprintf('[%s]null[%s]', $baseLabel, $label);
                }
            } else {
                // Video: scale/crop to canvas, normalize fps, time-stretch, then freeze-frame pad the rest
                $dur = $this->probeVideoDuration($seg['file']);
                $dur = max(0.1, min($dur, 30.0));
                $ptsFactor = 1.0;
                $stretchedDur = $dur;
                if ($dur > 0.0) {
                    // Time-stretch to fill holdDuration
                    // setpts factor >1 slows down; <1 speeds up
                    $ptsFactor = $holdDuration / $dur;
                    $ptsFactor = max(0.5, min(2.0, $ptsFactor));
                    $stretchedDur = $dur * $ptsFactor;
                }
                // Calculate how many frames we need to pad (transition duration worth of frames)
                $padFrames = max(0, (int)ceil(($clipDuration - $stretchedDur) * $fps));

                $parts[] = sprintf(
                    '[%1$d:v]scale=%2$d:%3$d:force_original_aspect_ratio=increase,' .
                    'crop=%2$d:%3$d,fps=%4$d,setsar=1,' .
                    'trim=duration=%7$s,setpts=PTS-STARTPTS,' .
                    'setpts=%5$s*PTS,setpts=PTS-STARTPTS,' .
                    'tpad=stop_mode=clone:stop_duration=%8$d[%9$s]',
                    $i,
                    $width,
                    $height,
                    $fps,
                    $this->formatFloat($ptsFactor),
                    $this->formatFloat($clipDuration),
                    $this->formatFloat($dur),
                    $padFrames,
                    $label,
                );
            }
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
            $totalDurationSeconds = $holdDuration * count($segments) + $transitionDuration;
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
        $totalDurationSeconds = $holdDuration * count($segments) + $transitionDuration;
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
        // Always append timestamp to ensure unique filename and avoid conflicts
        $baseName = preg_replace('/\.mp4$/i', '', $fileName);
        $fileName = $baseName . ' ' . date('Ymd-His') . '.mp4';

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

    /**
     * Replace landscape images with GCam motion videos when available.
     * @param array<int,string> $files
     * @return array<int,string>
     */
    private function preferVideoWhereAvailable(array $files): array {
        $out = [];
        foreach ($files as $img) {
            $lower = strtolower($img);
            $mp4 = null;
            if (str_ends_with($lower, '.jpg') || str_ends_with($lower, '.jpeg') || str_ends_with($lower, '.heic')) {
                $base = substr($img, 0, strrpos($img, '.'));
                if ($base !== false) {
                    $candidate = $base . '.mp4';
                    if ($this->isLikelyValidMp4($candidate)) {
                        $mp4 = $candidate;
                    }
                }
            }
            $out[] = $mp4 ?: $img;
        }
        return $out;
    }

    private function isVideoFile(string $path): bool {
        $lower = strtolower($path);
        return str_ends_with($lower, '.mp4') || str_ends_with($lower, '.mov') || str_ends_with($lower, '.avi');
    }

    private function isLikelyValidMp4(string $path): bool {
        if (!is_file($path)) { return false; }
        $size = @filesize($path);
        if (!is_int($size) || $size < 10240) { return false; } // at least 10KB
        $fh = @fopen($path, 'rb');
        if ($fh === false) { return false; }
        $head = '';
        try {
            $head = @fread($fh, 4096) ?: '';
        } finally {
            fclose($fh);
        }
        if ($head === '') { return false; }
        // ftyp must be in the beginning chunk
        $p = strpos($head, 'ftyp');
        if ($p === false || $p > 64) { return false; }
        $brand = substr($head, $p + 4, 8) ?: '';
        $brandOk = str_contains($brand, 'isom') || str_contains($brand, 'mp42') || str_contains($brand, 'iso5') || str_contains($brand, 'avc1');
        return $brandOk;
    }

    private function probeVideoDuration(string $path): float {
        if ($path === '' || !is_file($path)) {
            return 0.0;
        }
        try {
            $cmd = ['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=nw=1:nk=1', $path];
            $proc = new Process($cmd);
            $proc->setTimeout(5);
            $proc->run();
            if ($proc->isSuccessful()) {
                $out = trim($proc->getOutput());
                $val = (float) $out;
                if ($val > 0 && is_finite($val)) {
                    return $val;
                }
            }
        } catch (\Throwable) {}
        return 0.0;
    }

}
