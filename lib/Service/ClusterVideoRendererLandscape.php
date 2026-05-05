<?php
namespace OCA\Journeys\Service;

use OCP\Files\IRootFolder;
use Symfony\Component\Process\Process;

class ClusterVideoRendererLandscape {
    use VideoRenderPrimitives;

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

        // Mirror portrait timing: hold and transition
        $holdDuration = max(0.5, $durationPerImage);
        $transitionDuration = min(0.8, max(0.2, $holdDuration * 0.3));
        $clipDuration = $holdDuration + $transitionDuration; // input lifespan to allow xfade overlap

        // Build segments to distinguish images from videos
        $segments = [];
        $maxPixels = 0;
        foreach ($files as $f) {
            $isVideo = $this->isVideoFile($f);
            $segments[] = ['type' => $isVideo ? 'video' : 'image', 'file' => $f];
            if (!$isVideo) {
                [$w, $h] = $this->orientedImageSize($f);
                if ($w > 0 && $h > 0) {
                    $maxPixels = max($maxPixels, $w * $h);
                }
            }
        }

        // Ensure we end on a still image when available to guarantee clean fade-out
        $segments = $this->ensureEndingStill($segments);

        $chunkSize = $this->resolveChunkSize($maxPixels);
        $parts = array_chunk($segments, max(1, $chunkSize));
        if (empty($parts)) {
            throw new \RuntimeException('No segments available for chunked rendering');
        }
        $totalChunks = count($parts);
        $this->emitProgress($outputHandler, sprintf('Rendering chunked landscape: %d parts', $totalChunks));

        $chunkClips = [];
        $idx = 0;
        foreach ($parts as $chunkSegments) {
            $this->emitProgress(
                $outputHandler,
                sprintf('Rendering chunk %d/%d (%d segments)', $idx + 1, $totalChunks, count($chunkSegments))
            );
            $chunkPath = $this->chunkOutputPath($workingDir, $idx);
            $duration = $this->renderChunk(
                $user,
                $chunkSegments,
                $chunkPath,
                $width,
                $height,
                $fps,
                $holdDuration,
                $transitionDuration,
                $clipDuration,
                null, // audio muxed after merge
                $outputHandler,
                null,
                $verbose,
                $albumName && $idx === 0 ? $albumName : null,
                false, // do not persist per chunk
            )['duration'];
            $chunkClips[] = ['path' => $chunkPath, 'duration' => $duration];
            $idx++;
        }

        $mergePath = $this->chunkMergeOutputPath($workingDir, 0);
        $this->emitProgress($outputHandler, sprintf('Merging chunked landscape video (%d parts)', $totalChunks));
        $merged = $this->mergeChunks($chunkClips, $transitionDuration, $fps, $mergePath, $outputHandler, $verbose);
        $finalPath = $merged['path'];
        $finalDuration = $merged['duration'];

        if ($audioTrack !== null && is_file($audioTrack)) {
            $finalPath = $this->muxAudio(
                $finalPath,
                $audioTrack,
                $finalDuration,
                $transitionDuration,
                $outputHandler,
                $verbose,
                $tmpOut,
            );
        } else {
            $outDir = dirname($tmpOut);
            if (!is_dir($outDir)) {
                @mkdir($outDir, 0777, true);
            }
            rename($finalPath, $tmpOut);
        }

        if ($outputPath !== null && $outputPath !== '') {
            return [
                'path' => $tmpOut,
                'storedInUserFiles' => false,
            ];
        }

        $virtualPath = $this->persistToUserFiles($user, $tmpOut, $preferredFileName, 'Journey-Landscape');
        return [
            'path' => $virtualPath,
            'storedInUserFiles' => true,
        ];
    }

    /**
     * Render a set of segments to a single video file.
     * @param array<int,array{type:string,file:string}> $segments
     * @return array{path:string,duration:float,storedInUserFiles:bool}
     */
    private function renderChunk(
        string $user,
        array $segments,
        string $outputPath,
        int $width,
        int $height,
        int $fps,
        float $holdDuration,
        float $transitionDuration,
        float $clipDuration,
        ?string $audioTrack,
        ?callable $outputHandler,
        ?string $preferredFileName,
        bool $verbose,
        ?string $albumName,
        bool $persistToUserFiles,
    ): array {
        $totalDurationSeconds = $holdDuration * count($segments) + $transitionDuration;

        $logLevel = $verbose ? 'info' : 'error';
        $cmd = $this->ffmpegBaseCmd($logLevel);
        if (!$verbose) {
            $cmd[] = '-nostats';
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
                    'zoompan=z=%4$s:x=%5$s:y=%6$s:d=%7$d:fps=%8$d:s=%2$dx%3$d,setsar=1,setpts=PTS-STARTPTS,' .
                    'settb=AVTB,fps=%8$d[%9$s]',
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
                    'tpad=stop_mode=clone:stop_duration=%8$d,' .
                    'settb=AVTB,fps=%4$d[%9$s]',
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
            // Add album overlay if first segment and no image overlay already applied
            if ($i === 0 && $albumName !== null && $albumName !== '' && $seg['type'] !== 'image') {
                $formatted = $this->titleFormatter->formatForVideo($albumName, $width, 0.8);
                $titleLabel = sprintf('title%d', $i);
                $parts[] = $this->titleFormatter->buildDrawtextFilter(
                    $label,
                    $titleLabel,
                    $formatted['text'],
                    $formatted['fontSize'],
                    4.0,
                    3 // shadow offset for landscape
                );
                $label = $titleLabel;
            }
            $prepLabels[] = '[' . $label . ']';
        }
        if (count($prepLabels) === 1) {
            $fadeDur = min(0.8, max(0.3, $totalDurationSeconds * 0.1));
            $fadeStart = max(0.0, $totalDurationSeconds - $fadeDur);
            $parts[] = sprintf(
                '%strim=duration=%s,fps=%d,fade=t=out:st=%s:d=%s,format=yuv420p[vout]',
                $prepLabels[0],
                $this->formatFloat($totalDurationSeconds),
                $fps,
                $this->formatFloat($fadeStart),
                $this->formatFloat($fadeDur),
            );
        } else {
            // xfade chain with fade transitions at holdDuration offsets
            $prev = trim($prepLabels[0], '[]');
            for ($i = 1; $i < count($prepLabels); $i++) {
                $out = ($i === count($prepLabels) - 1) ? 'mix_last' : sprintf('mix%d', $i);
                $parts[] = sprintf(
                    '[%1$s][%2$s]xfade=transition=fade:duration=%3$s:offset=%4$s[%5$s]',
                    $prev,
                    trim($prepLabels[$i], '[]'),
                    $this->formatFloat($transitionDuration),
                    $this->formatFloat($holdDuration * $i),
                    $out,
                );
                $prev = $out;
            }
            $final = ($prev === 'kseg0') ? 'kseg0' : 'mix_last';
            $fadeDur = min(0.8, max(0.3, $totalDurationSeconds * 0.1));
            $fadeStart = max(0.0, $totalDurationSeconds - $fadeDur);
            $parts[] = sprintf(
                '[%1$s]trim=duration=%2$s,fps=%3$d,fade=t=out:st=%4$s:d=%5$s,format=yuv420p[vout]',
                $final,
                $this->formatFloat($totalDurationSeconds),
                $fps,
                $this->formatFloat($fadeStart),
                $this->formatFloat($fadeDur),
            );
        }

        $cmd[] = '-filter_complex';
        $cmd[] = implode(';', $parts);
        $cmd[] = '-map';
        $cmd[] = '[vout]';
        if ($audioInputIndex !== null) {
            $cmd[] = '-map';
            $cmd[] = sprintf('%d:a:0', $audioInputIndex);
            // Gentle fade-out at the end; match xfade total video duration
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
        $cmd[] = $outputPath;

        // Ensure output directory exists
        $outDir = dirname($outputPath);
        if (!is_dir($outDir)) {
            @mkdir($outDir, 0777, true);
        }

        $process = new Process($cmd);
        $process->setTimeout(null);
        $process->setIdleTimeout(null);
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

        if (!$persistToUserFiles) {
            return [
                'path' => $outputPath,
                'storedInUserFiles' => false,
                'duration' => $totalDurationSeconds,
            ];
        }

        $virtualPath = $this->persistToUserFiles($user, $outputPath, $preferredFileName, 'Journey-Landscape');
        return [
            'path' => $virtualPath,
            'storedInUserFiles' => true,
            'duration' => $totalDurationSeconds,
        ];
    }

    /**
     * @param array<int,array{path:string,duration:float}> $clips
     * @return array{path:string,duration:float}
     */
    private function mergeChunks(
        array $clips,
        float $transitionDuration,
        int $fps,
        string $outputPath,
        ?callable $outputHandler,
        bool $verbose,
    ): array {
        // Probe actual on-disk durations: caller-supplied durations are formula-based and overstate length when chunks contain motion videos shorter than their nominal slot. Feeding inflated durations to xfade as offsets makes the chain silently collapse — xfade with offset>left_duration drops the rest of the timeline.
        $actualDurations = [];
        foreach ($clips as $clip) {
            $probe = new Process(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=nw=1:nk=1', $clip['path']]);
            $probe->run();
            $val = (float)trim($probe->getOutput());
            if ($val <= 0.0) {
                $val = (float)$clip['duration'];
            }
            $actualDurations[] = $val;
        }

        if (count($clips) === 1) {
            // Single chunk, just copy
            $src = $clips[0]['path'];
            $outDir = dirname($outputPath);
            if (!is_dir($outDir)) {
                @mkdir($outDir, 0777, true);
            }
            rename($src, $outputPath);
            return ['path' => $outputPath, 'duration' => $actualDurations[0]];
        }

        $inputs = [];
        foreach ($clips as $clip) {
            $inputs[] = '-i';
            $inputs[] = $clip['path'];
        }

        $parts = [];
        // Normalize each input to constant fps and format for xfade (ffmpeg 7 requires CFR)
        for ($i = 0; $i < count($clips); $i++) {
            $parts[] = sprintf('[%1$d:v]fps=%2$d,format=yuv420p[vin%1$d]', $i, $fps);
        }
        $prevLabel = 'vin0';
        // xfade `offset` is measured from the start of the left input, which here is the running merged stream — must be cumulative, not the previous chunk's standalone duration.
        $cumDuration = $actualDurations[0];
        for ($i = 1; $i < count($clips); $i++) {
            $outLabel = ($i === count($clips) - 1) ? 'vout' : sprintf('m%d', $i);
            $offset = max(0.0, $cumDuration - $transitionDuration);
            $parts[] = sprintf(
                '[%1$s][vin%2$d]xfade=transition=fade:duration=%3$s:offset=%4$s[%5$s]',
                $prevLabel,
                $i,
                $this->formatFloat($transitionDuration),
                $this->formatFloat($offset),
                $outLabel,
            );
            $prevLabel = $outLabel;
            $cumDuration += $actualDurations[$i] - $transitionDuration;
        }
        $totalDuration = $cumDuration;

        $logLevel = $verbose ? 'info' : 'error';
        $cmd = array_merge(
            $this->ffmpegBaseCmd($logLevel),
            $inputs,
            ['-filter_complex', implode(';', $parts), '-map', '[vout]', '-r', (string)$fps, '-pix_fmt', 'yuv420p', '-movflags', '+faststart', $outputPath]
        );
        if (!$verbose) {
            array_splice($cmd, 3, 0, ['-nostats']); // insert after loglevel
        }

        $process = new Process($cmd);
        $process->setTimeout(null);
        $process->setIdleTimeout(null);
        $process->run(function (string $type, string $buffer) use ($outputHandler, $verbose): void {
            if ($outputHandler !== null) {
                if ($type === Process::ERR && $verbose) {
                    $outputHandler($type, $buffer);
                } elseif ($type === Process::OUT && $verbose) {
                    $outputHandler($type, $buffer);
                }
            }
        });

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('ffmpeg failed while merging chunks: ' . $process->getErrorOutput());
        }

        return ['path' => $outputPath, 'duration' => $totalDuration];
    }

    private function muxAudio(
        string $videoPath,
        string $audioPath,
        float $videoDuration,
        float $transitionDuration,
        ?callable $outputHandler,
        bool $verbose,
        string $outputPath,
    ): string {
        $logLevel = $verbose ? 'info' : 'error';
        $fadeDur = min(5.0, max(0.5, $videoDuration * 0.08));
        $fadeStart = max(0.0, $videoDuration - $fadeDur);

        $cmd = $this->ffmpegBaseCmd($logLevel);
        if (!$verbose) {
            $cmd[] = '-nostats';
        }
        $cmd[] = '-i';
        $cmd[] = $videoPath;
        $cmd[] = '-stream_loop';
        $cmd[] = '-1';
        $cmd[] = '-i';
        $cmd[] = $audioPath;
        $cmd[] = '-filter_complex';
        $cmd[] = sprintf(
            '[1:a]atrim=0:%1$s,asetpts=PTS-STARTPTS,afade=t=out:st=%2$s:d=%3$s[aout]',
            $this->formatFloat($videoDuration),
            $this->formatFloat($fadeStart),
            $this->formatFloat($fadeDur)
        );
        $cmd[] = '-map';
        $cmd[] = '0:v:0';
        $cmd[] = '-map';
        $cmd[] = '[aout]';
        $cmd[] = '-shortest';
        $cmd[] = '-c:v';
        $cmd[] = 'copy';
        $cmd[] = '-c:a';
        $cmd[] = 'aac';
        $cmd[] = '-b:a';
        $cmd[] = '192k';
        $cmd[] = '-movflags';
        $cmd[] = '+faststart';
        $cmd[] = $outputPath;

        $outDir = dirname($outputPath);
        if (!is_dir($outDir)) {
            @mkdir($outDir, 0777, true);
        }

        $process = new Process($cmd);
        $process->setTimeout(null);
        $process->setIdleTimeout(null);
        $process->run(function (string $type, string $buffer) use ($outputHandler, $verbose): void {
            if ($type === Process::ERR && $outputHandler !== null && $verbose) {
                $outputHandler($type, $buffer);
            }
        });
        if (!$process->isSuccessful()) {
            throw new \RuntimeException('ffmpeg failed while muxing audio: ' . $process->getErrorOutput());
        }

        return $outputPath;
    }

    private function chunkOutputPath(string $workingDir, int $index): string {
        return $workingDir . '/chunk_' . sprintf('%03d', $index) . '.mp4';
    }

    private function chunkMergeOutputPath(string $workingDir, int $index): string {
        return $workingDir . '/chunk_merge_' . sprintf('%03d', $index) . '.mp4';
    }

    /**
     * Ensure the final segment is a still image when available, to guarantee clean fade-out.
     * If the last segment is motion/video and there is any image earlier, move the last image to the end.
     * @param array<int,array{type:string,file:string}> $segments
     * @return array<int,array{type:string,file:string}>
     */
    private function ensureEndingStill(array $segments): array {
        if (count($segments) < 2) {
            return $segments;
        }
        $last = $segments[array_key_last($segments)];
        if ($last['type'] !== 'image') {
            for ($i = count($segments) - 2; $i >= 0; $i--) {
                if ($segments[$i]['type'] === 'image') {
                    $imgSeg = $segments[$i];
                    array_splice($segments, $i, 1);
                    $segments[] = $imgSeg;
                    break;
                }
            }
        }
        return $segments;
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

}
