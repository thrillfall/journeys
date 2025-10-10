<?php
namespace OCA\Journeys\Service;

use OCP\Files\IRootFolder;
use Symfony\Component\Process\Process;

class ClusterVideoRenderer {
    public function __construct(
        private IRootFolder $rootFolder,
        private ClusterVideoMusicProvider $musicProvider,
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

        // Build a sequence of render segments (portrait Ken Burns and occasional landscape stacks)
        $segments = $this->planSegments($files);
        if (empty($segments)) {
            throw new \RuntimeException('No renderable images available (need at least one portrait or 3 landscapes)');
        }

        $durationPerImage = max(0.5, $durationPerImage);
        $transitionDuration = min(0.8, max(0.2, $durationPerImage * 0.3));
        // Determine output size based on the first portrait if available, else fall back to requested width and 16:9 height.
        [$targetWidth, $targetHeight] = $this->determineOutputDimensionsFromSegments($width, $segments);

        $audioTrack = $this->musicProvider->pickRandomTrack();

        $this->runFfmpeg(
            $segments,
            $tmpOut,
            $targetWidth,
            $targetHeight,
            $fps,
            $durationPerImage,
            $transitionDuration,
            $audioTrack,
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
        array $segments,
        string $outputPath,
        int $width,
        int $height,
        int $fps,
        float $durationPerImage,
        float $transitionDuration,
        ?string $audioTrack,
        ?callable $outputHandler,
    ): void {
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

        $cmd = ['ffmpeg', '-y', '-hide_banner', '-nostats', '-loglevel', 'error'];

        // Register all inputs (portrait: 1 per segment, stack: 3 per segment)
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
        );

        $cmd[] = '-filter_complex';
        $cmd[] = $filterGraph;
        $cmd[] = '-map';
        $cmd[] = $outputLabel;
        if ($audioInputIndex !== null) {
            $cmd[] = '-map';
            $cmd[] = sprintf('%d:a:0', $audioInputIndex);
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

    private function buildFilterGraphWithSegments(
        array $segments,
        int $width,
        int $height,
        int $fps,
        float $holdDuration,
        float $transitionDuration,
        float $clipDuration,
    ): array {
        $filterParts = [];
        $frameCount = max(2, (int) round($clipDuration * $fps));

        $inputIndex = 0;
        $segmentOutputLabels = [];
        $rowH = $this->makeEven((int) floor($height / 3));
        $holdStr = $this->formatFloat($holdDuration);

        foreach ($segments as $si => $seg) {
            if ($seg['type'] === 'kenburns') {
                $motions = $this->buildKenBurnsExpressions($si, $frameCount);
                $filterParts[] = sprintf(
                    '[%1$d:v]scale=%2$d:%3$d:force_original_aspect_ratio=increase,' .
                    'crop=%2$d:%3$d,' .
                    'zoompan=z=%4$s:x=%5$s:y=%6$s:d=%7$d:fps=%8$d:s=%2$dx%3$d,setsar=1[kseg%9$d]',
                    $inputIndex,
                    $width,
                    $height,
                    $motions['z'],
                    $motions['x'],
                    $motions['y'],
                    $frameCount,
                    $fps,
                    $si,
                );
                $segmentOutputLabels[] = sprintf('kseg%d', $si);
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
                    '[%1$d:v]scale=%2$d:-1,setsar=1[sc%3$da];' .
                    '[sc%3$da]pad=%2$d:%4$d:(ow-iw)/2:(oh-ih)/2:black[%5$s]',
                    $inputIndex,
                    $width,
                    $si,
                    $rowH,
                    $p1,
                );
                $filterParts[] = sprintf(
                    '[%1$d:v]scale=%2$d:-1,setsar=1[sc%3$db];' .
                    '[sc%3$db]pad=%2$d:%4$d:(ow-iw)/2:(oh-ih)/2:black[%5$s]',
                    $inputIndex + 1,
                    $width,
                    $si,
                    $rowH,
                    $p2,
                );
                $filterParts[] = sprintf(
                    '[%1$d:v]scale=%2$d:-1,setsar=1[sc%3$dc];' .
                    '[sc%3$dc]pad=%2$d:%4$d:(ow-iw)/2:(oh-ih)/2:black[%5$s]',
                    $inputIndex + 2,
                    $width,
                    $si,
                    $rowH,
                    $p3,
                );

                // Slide expressions with center pause:
                // Split holdDuration into in, hold(center), out. Aim for 2s hold at center, clamp if too short.
                $centerHold = min(2.0, max(0.0, $holdDuration - 0.8)); // leave at least ~0.4s for in and out each
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
            [$w, $h] = $this->safeImageSize($f);
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
