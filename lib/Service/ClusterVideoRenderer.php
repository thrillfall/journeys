<?php
namespace OCA\Journeys\Service;

use OCP\Files\IRootFolder;
use Symfony\Component\Process\Process;
use Psr\Log\LoggerInterface;

class ClusterVideoRenderer {
    private const MIN_SEGMENTS_PER_PASS = 4;
    private const MAX_SEGMENTS_PER_PASS = 60;
    public function __construct(
        private IRootFolder $rootFolder,
        private ClusterVideoMusicProvider $musicProvider,
        private LoggerInterface $logger,
        private VideoTitleFormatter $titleFormatter,
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

        $this->logger->info('ClusterVideoRenderer: Starting render', [
            'app' => 'journeys',
            'user' => $user,
            'fileCount' => count($files),
        ]);

        $tmpOut = $outputPath ?: ($workingDir . '/output.mp4');

        // Build a sequence of render segments (portrait Ken Burns and occasional landscape stacks)
        $segments = $this->planSegments($files);
        $this->logger->info('ClusterVideoRenderer: Segments planned', [
            'app' => 'journeys',
            'segmentCount' => count($segments),
        ]);

        // Replace portrait image segments with video segments when a GCam trailer was extracted (if enabled)
        if ($includeMotion) {
            $segments = $this->preferVideoWhereAvailable($segments);
            $this->logger->info('ClusterVideoRenderer: After motion video replacement', [
                'app' => 'journeys',
                'segmentCount' => count($segments),
            ]);
        }
        if (empty($segments)) {
            throw new \RuntimeException('No renderable images available (need at least one portrait or 3 landscapes)');
        }

        $durationPerImage = max(0.5, $durationPerImage);
        $transitionDuration = min(0.8, max(0.2, $durationPerImage * 0.3));
        // Determine output size based on the first portrait if available, else fall back to requested width and 16:9 height.
        [$targetWidth, $targetHeight] = $this->determineOutputDimensionsFromSegments($width, $segments);

        $audioTrack = $this->musicProvider->pickRandomTrack();

        $segmentThreshold = $this->determineDynamicThreshold($segments);

        // Ensure we end on a still image when available to guarantee clean fade-out
        $segments = $this->ensureEndingStill($segments);

        $this->renderChunked(
            $segments,
            $tmpOut,
            $workingDir,
            $targetWidth,
            $targetHeight,
            $fps,
            $durationPerImage,
            $transitionDuration,
            $audioTrack,
            $outputHandler,
            $verbose,
            $albumName,
            $segmentThreshold,
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
        array $segments,
        string $outputPath,
        int $width,
        int $height,
        int $fps,
        float $durationPerImage,
        float $transitionDuration,
        ?string $audioTrack,
        ?callable $outputHandler,
        bool $verbose,
        ?string $albumName,
    ): float {
        if (empty($segments)) {
            throw new \RuntimeException('No files provided to ffmpeg');
        }

        $width = $this->makeEven($width);
        $height = $this->makeEven($height);

        $segmentCount = count($segments);
        $holdDuration = $durationPerImage; // per-segment visible duration before xfade
        $clipDuration = $holdDuration + $transitionDuration; // input lifespan so xfade has material to blend
        $totalDurationSeconds = $segmentCount === 1
            ? $clipDuration
            : max(0.1, $holdDuration * $segmentCount + $transitionDuration);

        $logLevel = $verbose ? 'info' : 'error';
        $cmd = ['ffmpeg', '-y', '-hide_banner', '-loglevel', $logLevel];
        if (!$verbose) {
            $cmd[] = '-nostats';
        }

        // Register all inputs (portrait: 1 per segment, stack: 3 per segment, video: 1 per segment)
        $flatInputs = [];
        foreach ($segments as $seg) {
            if ($seg['type'] === 'kenburns') {
                $file = $seg['inputs'][0];
                $cmd[] = '-loop';
                $cmd[] = '1';
                $cmd[] = '-t';
                $cmd[] = $this->formatFloat($clipDuration);
                $cmd[] = '-i';
                $cmd[] = $file;
                $flatInputs[] = [$file];
            } elseif ($seg['type'] === 'video') {
                $file = $seg['inputs'][0];
                $cmd[] = '-i';
                $cmd[] = $file;
                $flatInputs[] = [$file];
            } elseif ($seg['type'] === 'stack') {
                $files = $seg['inputs'];
                foreach ($files as $f) {
                    $cmd[] = '-loop';
                    $cmd[] = '1';
                    $cmd[] = '-t';
                    $cmd[] = $this->formatFloat($clipDuration);
                    $cmd[] = '-i';
                    $cmd[] = $f;
                }
                $flatInputs[] = $files; // three entries
            }
        }

        $audioInputIndex = null;
        if ($audioTrack !== null) {
            $cmd[] = '-stream_loop';
            $cmd[] = '-1';
            $cmd[] = '-i';
            $cmd[] = $audioTrack;
            // audio index is after all image inputs
            $audioInputIndex = 0;
            foreach ($flatInputs as $arr) { $audioInputIndex += count($arr); }
        }

        [$filterGraph, $outputLabel] = $this->buildFilterGraphWithSegments(
            $segments,
            $width,
            $height,
            $fps,
            $holdDuration,
            $transitionDuration,
            $clipDuration,
            $albumName,
        );

        // If we have an audio input, append an audio fade-out filter graph and map it by label
        $audioMapLabel = null;
        if ($audioInputIndex !== null) {
            $fadeDur = min(5.0, max(0.5, $totalDurationSeconds * 0.08));
            $fadeStart = max(0.0, $totalDurationSeconds - $fadeDur);
            // Build audio chain: trim to total duration (avoid overrun), reset PTS, apply fade-out
            $audioLabel = 'afout';
            $filterGraph .= ';' . sprintf('[%1$d:a:0]atrim=0:%2$s,asetpts=PTS-STARTPTS,afade=t=out:st=%3$s:d=%4$s[%5$s]',
                $audioInputIndex,
                $this->formatFloat($totalDurationSeconds),
                $this->formatFloat($fadeStart),
                $this->formatFloat($fadeDur),
                $audioLabel,
            );
            $audioMapLabel = '[' . $audioLabel . ']';
        }

        $cmd[] = '-filter_complex';
        $cmd[] = $filterGraph;
        $cmd[] = '-map';
        $cmd[] = $outputLabel;
        if ($audioInputIndex !== null) {
            $cmd[] = '-map';
            $cmd[] = $audioMapLabel ?? sprintf('%d:a:0', $audioInputIndex);
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
                if ($outputHandler !== null) {
                    // In verbose mode, pass through all stderr; otherwise filter warnings
                    if ($verbose || !$this->shouldSuppressWarning($buffer)) {
                        $outputHandler($type, $buffer);
                    }
                }
            }
        });

        if (!$process->isSuccessful()) {
            $exitCode = $process->getExitCode();
            $errorOutput = $process->getErrorOutput();
            throw new \RuntimeException(sprintf(
                'ffmpeg failed with exit code %d. Error output: %s',
                $exitCode,
                $errorOutput
            ));
        }

        return $totalDurationSeconds;
    }

    private function renderChunked(
        array $segments,
        string $finalOutputPath,
        string $workingDir,
        int $width,
        int $height,
        int $fps,
        float $durationPerImage,
        float $transitionDuration,
        ?string $audioTrack,
        ?callable $outputHandler,
        bool $verbose,
        ?string $albumName,
        int $chunkSize,
    ): void {
        $clipFiles = [];
        $chunkIndex = 0;
        $chunkedSegments = array_chunk($segments, max(1, $chunkSize));
        $totalChunks = count($chunkedSegments);

        foreach ($chunkedSegments as $chunk) {
            $clipPath = sprintf('%s/chunk_%03d.mp4', rtrim($workingDir, '/'), $chunkIndex);
            $chunkAlbumName = ($chunkIndex === 0) ? $albumName : null;
            $this->emitProgress(
                $outputHandler,
                sprintf('Rendering chunk %d/%d (%d segments)', $chunkIndex + 1, $totalChunks, count($chunk))
            );
            $duration = $this->runFfmpeg(
                $chunk,
                $clipPath,
                $width,
                $height,
                $fps,
                $durationPerImage,
                $transitionDuration,
                null,
                $outputHandler,
                $verbose,
                $chunkAlbumName,
            );
            $clipFiles[] = ['path' => $clipPath, 'duration' => $duration];
            $chunkIndex++;
        }

        if (empty($clipFiles)) {
            throw new \RuntimeException('Chunk rendering received no clips.');
        }

        $current = array_shift($clipFiles);
        $mergeIndex = 0;
        foreach ($clipFiles as $clip) {
            $this->emitProgress(
                $outputHandler,
                sprintf('Merging chunk %d/%d', $mergeIndex + 1, max(1, count($clipFiles)))
            );
            $current = $this->xfadeClipPair(
                $current,
                $clip,
                $transitionDuration,
                $fps,
                sprintf('%s/chunk_merge_%03d.mp4', rtrim($workingDir, '/'), $mergeIndex++),
                $outputHandler,
                $verbose,
            );
        }

        $videoOnlyPath = $current['path'];
        $totalDuration = $current['duration'];

        if ($audioTrack !== null) {
            $videoOnlyPath = $this->muxAudio(
                $videoOnlyPath,
                $audioTrack,
                $totalDuration,
                $transitionDuration,
                $outputHandler,
                $verbose,
                $finalOutputPath,
            );
        } else {
            rename($videoOnlyPath, $finalOutputPath);
        }
    }

    /**
     * @param array{path:string,duration:float} $left
     * @param array{path:string,duration:float} $right
     * @return array{path:string,duration:float}
     */
    private function xfadeClipPair(
        array $left,
        array $right,
        float $transitionDuration,
        int $fps,
        string $outputPath,
        ?callable $outputHandler,
        bool $verbose,
    ): array {
        $offset = max(0.0, $left['duration'] - $transitionDuration);
        $logLevel = $verbose ? 'info' : 'error';
        $cmd = [
            'ffmpeg', '-y', '-hide_banner', '-loglevel', $logLevel,
            '-i', $left['path'],
            '-i', $right['path'],
            '-filter_complex', sprintf('[0:v][1:v]xfade=transition=fade:duration=%s:offset=%s[vout]',
                $this->formatFloat($transitionDuration),
                $this->formatFloat($offset)
            ),
            '-map', '[vout]',
            '-r', (string)$fps,
            '-pix_fmt', 'yuv420p',
            '-movflags', '+faststart',
            $outputPath,
        ];

        $process = new Process($cmd);
        $process->setTimeout(null);
        $process->setIdleTimeout(null);
        $process->run(function (string $type, string $buffer) use ($outputHandler, $verbose): void {
            if ($outputHandler === null) {
                return;
            }
            if ($type === Process::ERR) {
                if ($verbose || !$this->shouldSuppressWarning($buffer)) {
                    $outputHandler($type, $buffer);
                }
            }
        });

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('ffmpeg failed while merging chunks: ' . $process->getErrorOutput());
        }

        @unlink($left['path']);
        @unlink($right['path']);

        $newDuration = max(0.0, $left['duration'] + $right['duration'] - $transitionDuration);
        return ['path' => $outputPath, 'duration' => $newDuration];
    }

    private function muxAudio(
        string $videoPath,
        string $audioTrack,
        float $totalDurationSeconds,
        float $transitionDuration,
        ?callable $outputHandler,
        bool $verbose,
        string $finalOutputPath,
    ): string {
        $logLevel = $verbose ? 'info' : 'error';
        $cmd = [
            'ffmpeg', '-y', '-hide_banner', '-loglevel', $logLevel,
            '-i', $videoPath,
            '-stream_loop', '-1', '-i', $audioTrack,
        ];

        $fadeDur = min(5.0, max(0.5, $totalDurationSeconds * 0.08));
        $fadeStart = max(0.0, $totalDurationSeconds - $fadeDur);
        $filterGraph = sprintf('[1:a]atrim=0:%1$s,asetpts=PTS-STARTPTS,afade=t=out:st=%2$s:d=%3$s[afout]',
            $this->formatFloat($totalDurationSeconds),
            $this->formatFloat($fadeStart),
            $this->formatFloat($fadeDur),
        );

        $cmd = array_merge($cmd, [
            '-filter_complex', $filterGraph,
            '-map', '0:v:0',
            '-map', '[afout]',
            '-c:v', 'copy',
            '-c:a', 'aac',
            '-b:a', '192k',
            '-shortest',
            '-movflags', '+faststart',
            $finalOutputPath,
        ]);

        $process = new Process($cmd);
        $process->setTimeout(null);
        $process->setIdleTimeout(null);
        $process->run(function (string $type, string $buffer) use ($outputHandler, $verbose): void {
            if ($outputHandler === null) {
                return;
            }
            if ($type === Process::ERR) {
                if ($verbose || !$this->shouldSuppressWarning($buffer)) {
                    $outputHandler($type, $buffer);
                }
            }
        });

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('ffmpeg failed while muxing audio: ' . $process->getErrorOutput());
        }

        @unlink($videoPath);
        return $finalOutputPath;
    }

    /**
     * Decide chunking purely by image resolution.
     * If any input exceeds 13 megapixels, use a small chunk size; otherwise render in one pass.
     *
     * @param array<int,array{type:string,inputs:array<int,string>}> $segments
     */
    private function determineDynamicThreshold(array $segments): int {
        $maxPixels = 0;
        foreach ($segments as $segment) {
            foreach ($segment['inputs'] ?? [] as $input) {
                [$w, $h] = $this->orientedImageSize($input);
                if ($w > 0 && $h > 0) {
                    $maxPixels = max($maxPixels, $w * $h);
                }
            }
        }

        // 13 MP threshold (13,000,000 pixels)
        if ($maxPixels > 13000000) {
            return max(self::MIN_SEGMENTS_PER_PASS, min(self::MAX_SEGMENTS_PER_PASS, 10));
        }

        // No need to chunk
        return self::MAX_SEGMENTS_PER_PASS;
    }

    private function emitProgress(?callable $outputHandler, string $message): void {
        if ($outputHandler === null) {
            return;
        }
        $outputHandler(Process::OUT, $message . PHP_EOL);
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
        // Always append timestamp to ensure unique filename and avoid conflicts
        $baseName = preg_replace('/\.mp4$/i', '', $fileName);
        $fileName = $baseName . ' ' . date('Ymd-His') . '.mp4';

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

    private function buildFilterGraphWithSegments(
        array $segments,
        int $width,
        int $height,
        int $fps,
        float $holdDuration,
        float $transitionDuration,
        float $clipDuration,
        ?string $albumName,
    ): array {
        $filterParts = [];
        $frameCount = max(2, (int) round($clipDuration * $fps));

        $inputIndex = 0;
        $segmentOutputLabels = [];
        $rowH = $this->makeEven((int) floor($height / 3));
        $holdStr = $this->formatFloat($holdDuration);

        foreach ($segments as $si => $seg) {
            if ($seg['type'] === 'kenburns') {
                $motion = $this->buildKenBurnsExpressions($si, $frameCount);
                $baseLabelIn = sprintf('kseg_base%d', $si);
                $baseLabelOut = sprintf('kseg%d', $si);
                $filterParts[] = sprintf(
                    '[%1$d:v]scale=%2$d:%3$d:force_original_aspect_ratio=increase,' .
                    'crop=%2$d:%3$d,' .
                    'zoompan=z=%4$s:x=%5$s:y=%6$s:d=%7$d:fps=%8$d:s=%2$dx%3$d,setsar=1,setpts=PTS-STARTPTS,' .
                    'settb=AVTB,fps=%8$d[%9$s]',
                    $inputIndex,
                    $width,
                    $height,
                    $motion['z'],
                    $motion['x'],
                    $motion['y'],
                    $frameCount,
                    $fps,
                    $baseLabelIn,
                );

                // Add album name overlay to first still image segment only
                if ($si === 0 && $albumName !== null && $albumName !== '') {
                    // Format album name for video overlay (calculates font size, wraps text, escapes for FFmpeg)
                    $formatted = $this->titleFormatter->formatForVideo($albumName, $width, 0.8);
                    // Build FFmpeg drawtext filter (4 seconds: fade in 0.5s, visible 3s, fade out 0.5s)
                    $filterParts[] = $this->titleFormatter->buildDrawtextFilter(
                        $baseLabelIn,
                        $baseLabelOut,
                        $formatted['text'],
                        $formatted['fontSize'],
                        4.0,
                        2 // shadow offset for portrait
                    );
                } else {
                    // No text, just pass through
                    $filterParts[] = sprintf('[%1$s]null[%2$s]', $baseLabelIn, $baseLabelOut);
                }

                $segmentOutputLabels[] = $baseLabelOut;
                $inputIndex += 1;
            } elseif ($seg['type'] === 'video') {
                // Prepare video: scale/crop to canvas, normalize fps, time-stretch, then freeze-frame pad the rest
                $vidPath = $segments[$si]['inputs'][0] ?? '';
                $dur = $this->probeVideoDuration(is_string($vidPath) ? $vidPath : '');
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

                $filterParts[] = sprintf(
                    '[%1$d:v]scale=%2$d:%3$d:force_original_aspect_ratio=increase,' .
                    'crop=%2$d:%3$d,fps=%4$d,setsar=1,' .
                    'trim=duration=%7$s,setpts=PTS-STARTPTS,' .
                    'setpts=%5$s*PTS,setpts=PTS-STARTPTS,' .
                    'tpad=stop_mode=clone:stop_duration=%8$d,' .
                    'settb=AVTB,fps=%4$d[vseg%9$d]',
                    $inputIndex,
                    $width,
                    $height,
                    $fps,
                    $this->formatFloat($ptsFactor),
                    $this->formatFloat($clipDuration),
                    $this->formatFloat($dur),
                    $padFrames,
                    $si,
                );
                $segmentOutputLabels[] = sprintf('vseg%d', $si);
                $inputIndex += 1;
            } elseif ($seg['type'] === 'stack') {
                // Three inputs compose one sliding stack segment
                $base = sprintf('base%d', $si);
                $p1 = sprintf('p%da', $si);
                $p2 = sprintf('p%db', $si);
                $p3 = sprintf('p%dc', $si);
                $o1 = sprintf('o%da', $si);
                $o2 = sprintf('o%db', $si);
                $out = sprintf('stack%d', $si);

                // Base color background for this segment
                $filterParts[] = sprintf(
                    'color=size=%dx%d:rate=%d:duration=%s[%s]',
                    $width,
                    $height,
                    $fps,
                    $this->formatFloat($clipDuration),
                    $base,
                );

                // Prepare each row: scale to width, pad to row height
                $filterParts[] = sprintf(
                    '[%1$d:v]scale=%2$d:%4$d:force_original_aspect_ratio=decrease,setsar=1[sc%3$da];' .
                    '[sc%3$da]pad=%2$d:%4$d:(ow-iw)/2:(oh-ih)/2:black[%5$s]',
                    $inputIndex,
                    $width,
                    $si,
                    $rowH,
                    $p1,
                );
                $filterParts[] = sprintf(
                    '[%1$d:v]scale=%2$d:%4$d:force_original_aspect_ratio=decrease,setsar=1[sc%3$db];' .
                    '[sc%3$db]pad=%2$d:%4$d:(ow-iw)/2:(oh-ih)/2:black[%5$s]',
                    $inputIndex + 1,
                    $width,
                    $si,
                    $rowH,
                    $p2,
                );
                $filterParts[] = sprintf(
                    '[%1$d:v]scale=%2$d:%4$d:force_original_aspect_ratio=decrease,setsar=1[sc%3$dc];' .
                    '[sc%3$dc]pad=%2$d:%4$d:(ow-iw)/2:(oh-ih)/2:black[%5$s]',
                    $inputIndex + 2,
                    $width,
                    $si,
                    $rowH,
                    $p3,
                );

                // Slide expressions with center pause:
                // Split holdDuration into in, hold(center), out. Aim for 2.25s hold at center, clamp if too short.
                $centerHold = min(2.25, max(0.0, $holdDuration - 0.8)); // leave at least ~0.4s for in and out each
                $minSlide = 0.4;
                $slideTime = max($minSlide * 2, $holdDuration - $centerHold);
                if ($slideTime < ($minSlide * 2)) {
                    $slideTime = $minSlide * 2;
                    $centerHold = max(0.0, $holdDuration - $slideTime);
                }
                $tin = max($minSlide, $slideTime / 2.0);
                $tout = max($minSlide, $slideTime / 2.0);

                $tinStr = $this->formatFloat($tin);
                $tholdStr = $this->formatFloat($centerHold);
                $toutStr = $this->formatFloat($tout);
                $c = '(main_w - W)/2';

                // Left -> center (pause) -> right
                $x1 = sprintf('if(lt(t,%1$s), -W + (t/%1$s)*(%2$s+W), if(lt(t,%1$s+%3$s), %2$s, %2$s + ((t-(%1$s+%3$s))/%4$s)*(main_w-%2$s)))',
                    $tinStr, $c, $tholdStr, $toutStr);
                // Right -> center (pause) -> left
                $x2 = sprintf('if(lt(t,%1$s), main_w - (t/%1$s)*(main_w-%2$s), if(lt(t,%1$s+%3$s), %2$s, %2$s - ((t-(%1$s+%3$s))/%4$s)*(%2$s+W)))',
                    $tinStr, $c, $tholdStr, $toutStr);
                $x3 = $x1;

                $filterParts[] = sprintf('[%1$s][%2$s]overlay=x=\'%3$s\':y=0:shortest=1[%4$s]', $base, $p1, $x1, $o1);
                $filterParts[] = sprintf('[%1$s][%2$s]overlay=x=\'%3$s\':y=%4$d:shortest=1[%5$s]', $o1, $p2, $x2, $rowH, $o2);
                $filterParts[] = sprintf('[%1$s][%2$s]overlay=x=\'%3$s\':y=%4$d:shortest=1[%5$s]', $o2, $p3, $x3, $rowH * 2, $out);

                $segmentOutputLabels[] = $out;
                $inputIndex += 3;
            }
        }

        // If only one segment, just output it
        if (count($segmentOutputLabels) === 1) {
            $filterParts[] = sprintf('[%s]format=yuv420p[vout]', $segmentOutputLabels[0]);
            return [implode(';', $filterParts), '[vout]'];
        }

        // Stitch with xfade
        $totalDuration = $holdDuration * count($segmentOutputLabels) + $transitionDuration;
        $prev = $segmentOutputLabels[0];
        for ($i = 1; $i < count($segmentOutputLabels); $i++) {
            $out = ($i === count($segmentOutputLabels) - 1) ? 'mix_last' : sprintf('mix%d', $i);
            $offset = $holdDuration * $i;
            $filterParts[] = sprintf(
                '[%1$s][%2$s]xfade=transition=fade:duration=%3$s:offset=%4$s[%5$s]',
                $prev,
                $segmentOutputLabels[$i],
                $this->formatFloat($transitionDuration),
                $this->formatFloat($offset),
                $out,
            );
            $prev = $out;
        }

        $finalLabel = $prev === $segmentOutputLabels[0] ? $segmentOutputLabels[0] : 'mix_last';
        // End fade-out (long enough to be visible even for short clips, including motion-only endings)
        $fadeDur = min(1.2, max(0.6, $totalDuration * 0.15));
        $fadeStart = max(0.0, $totalDuration - $fadeDur);
        $filterParts[] = sprintf(
            '[%1$s]trim=duration=%2$s,fps=%3$d,fade=t=out:st=%4$s:d=%5$s,format=yuv420p[vout]',
            $finalLabel,
            $this->formatFloat($totalDuration),
            $fps,
            $this->formatFloat($fadeStart),
            $this->formatFloat($fadeDur),
        );

        return [implode(';', $filterParts), '[vout]'];
    }

    /**
     * @param array<int,array{type:string,inputs:array<int,string>}> $segments
     * @return array<int,array{type:string,inputs:array<int,string>}> 
     */
    private function preferVideoWhereAvailable(array $segments): array {
        $out = [];
        foreach ($segments as $seg) {
            if (($seg['type'] ?? '') === 'kenburns' && !empty($seg['inputs'][0])) {
                $img = (string)$seg['inputs'][0];
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
                if ($mp4) {
                    $out[] = ['type' => 'video', 'inputs' => [$mp4]];
                } else {
                    $out[] = $seg;
                }
            } else {
                $out[] = $seg;
            }
        }
        return $out;
    }

    /**
     * Ensure the final segment is a still image when available, to guarantee clean fade-out.
     * If the last segment is motion/video and there is any image earlier, move the last image to the end.
     * @param array<int,array{type:string,inputs:array<int,string>}> $segments
     * @return array<int,array{type:string,inputs:array<int,string>}>
     */
    private function ensureEndingStill(array $segments): array {
        if (count($segments) < 2) {
            return $segments;
        }
        $last = $segments[array_key_last($segments)];
        if (($last['type'] ?? '') !== 'kenburns') {
            return $segments;
        }
        for ($i = count($segments) - 2; $i >= 0; $i--) {
            if (($segments[$i]['type'] ?? '') === 'kenburns') {
                $imgSeg = $segments[$i];
                array_splice($segments, $i, 1);
                $segments[] = $imgSeg;
                break;
            }
        }
        return $segments;
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

    /**
     * @param int $requestedWidth
     * @param array<int, string> $files
     * @return array{0:int,1:int}
     */
    private function determineOutputDimensions(int $requestedWidth, array $files): array {
        $longEdge = max(320, $requestedWidth);
        foreach ($files as $file) {
            [$imgWidth, $imgHeight] = $this->orientedImageSize($file);
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
     * Choose output dims from first portrait in segments; else fall back to requested width and 16:9.
     * @param int $requestedWidth
     * @param array<int,array{type:string,inputs:array<int,string>}> $segments
     * @return array{0:int,1:int}
     */
    private function determineOutputDimensionsFromSegments(int $requestedWidth, array $segments): array {
        $portraitFiles = [];
        foreach ($segments as $seg) {
            if ($seg['type'] === 'kenburns') {
                $portraitFiles[] = $seg['inputs'][0];
            }
        }
        if (!empty($portraitFiles)) {
            return $this->determineOutputDimensions($requestedWidth, $portraitFiles);
        }
        $w = $this->makeEven(max(320, $requestedWidth));
        $h = $this->determineOutputHeight($w);
        return [$w, $h];
    }

    /**
     * @param array<int, string> $files
     * @return array<int, string>
     */
    private function filterPortraitFiles(array $files): array {
        $result = [];
        foreach ($files as $file) {
            [$width, $height] = $this->orientedImageSize($file);
            if ($width > 0 && $height > 0 && $height > $width) {
                $result[] = $file;
            }
        }

        return $result;
    }

    /**
     * Build a mixed sequence of segments: portrait Ken Burns clips and occasional 3-wide landscape stacks.
     * Heuristic: after every 4 portrait segments, insert one stack segment if at least 3 landscapes remain.
     * Order preservation: portraits remain in chronological order; landscapes are consumed in-order when stacked.
     * @param array<int,string> $files
     * @return array<int,array{type:string,inputs:array<int,string>}> Segments to render in order
     */
    private function planSegments(array $files): array {
        $portraits = [];
        $landscapes = [];
        foreach ($files as $f) {
            [$w, $h] = $this->orientedImageSize($f);
            if ($w > 0 && $h > 0) {
                if ($h > $w) { $portraits[] = $f; } else { $landscapes[] = $f; }
            }
        }

        $segments = [];
        $pIdx = 0;
        $lIdx = 0;
        $portraitsBeforeStack = 0;

        while ($pIdx < count($portraits) || ($lIdx + 2) < count($landscapes)) {
            if ($pIdx < count($portraits)) {
                $segments[] = ['type' => 'kenburns', 'inputs' => [$portraits[$pIdx]]];
                $pIdx++;
                $portraitsBeforeStack++;
            } else {
                $portraitsBeforeStack = 4; // force landscape stack use if no portraits left
            }

            if ($portraitsBeforeStack >= 4 && ($lIdx + 2) < count($landscapes)) {
                $segments[] = [
                    'type' => 'stack',
                    'inputs' => [
                        $landscapes[$lIdx],
                        $landscapes[$lIdx + 1],
                        $landscapes[$lIdx + 2],
                    ],
                ];
                $lIdx += 3;
                $portraitsBeforeStack = 0;
            }
        }

        // If no portraits at all, but we have >=3 landscapes, ensure at least one stack
        if (empty($segments) && count($landscapes) >= 3) {
            $segments[] = ['type' => 'stack', 'inputs' => [$landscapes[0], $landscapes[1], $landscapes[2]]];
        }

        return $segments;
    }

    /**
     * @return array{0:int,1:int}
     */
    private function safeImageSize(string $file): array {
        $info = @getimagesize($file);
        if ($info === false || !isset($info[0], $info[1])) {
            return [0, 0];
        }
        return [(int)$info[0], (int)$info[1]];
    }

    /**
     * Compute oriented dimensions using EXIF Orientation when present (for JPEGs).
     * @return array{0:int,1:int}
     */
    private function orientedImageSize(string $path): array {
        [$w, $h] = $this->safeImageSize($path);
        if ($w <= 0 || $h <= 0) {
            return [$w, $h];
        }

        $lower = strtolower($path);
        if (!str_ends_with($lower, '.jpg') && !str_ends_with($lower, '.jpeg')) {
            return [$w, $h];
        }

        try {
            if (function_exists('exif_read_data')) {
                $exif = @exif_read_data($path);
                if (is_array($exif) && isset($exif['Orientation'])) {
                    $orientation = (int)$exif['Orientation'];
                    if (in_array($orientation, [5, 6, 7, 8], true)) {
                        return [$h, $w];
                    }
                }
            }
        } catch (\Throwable) {
            // ignore EXIF issues, fallback to raw size
        }

        return [$w, $h];
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
