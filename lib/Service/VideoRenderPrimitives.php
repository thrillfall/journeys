<?php
namespace OCA\Journeys\Service;

use Symfony\Component\Process\Process;

/**
 * Shared primitives used by both ClusterVideoRenderer (portrait) and
 * ClusterVideoRendererLandscape. Extracted as a trait, not a base class, to
 * avoid the template-method coupling that has historically broken both
 * renderers when a shared base tried to dispatch to orientation-specific
 * overrides. Each renderer keeps full control of its own render() and
 * filter-graph code; this trait only supplies leaf helpers whose bodies were
 * already character-identical between the two classes.
 *
 * Required properties on the using class:
 *   - private \OCP\Files\IRootFolder $rootFolder  (for persistToUserFiles)
 */
trait VideoRenderPrimitives {
    private const DUCKED_MUSIC_DB = -16.5;
    // Seconds the music takes to dip under a clip and to come back, centred on
    // the cut; a hard switch between full and ducked music sounds like a glitch.
    private const DUCK_RAMP_SECONDS = 1.0;
    private const CLIP_AUDIO_FADE_SECONDS = 0.3;
    // Phone clips are often recorded far quieter than the music bed; level them
    // towards this mean, but never boost by more than CLIP_MAX_GAIN_DB so a
    // clip of near-silence does not turn into amplified hiss.
    private const CLIP_TARGET_MEAN_DB = -20.0;
    private const CLIP_MAX_GAIN_DB = 20.0;

    private function makeEven(int $value): int {
        return ($value % 2 === 0) ? $value : $value + 1;
    }

    /**
     * Adaptive chunk size based on the largest source image. Each chunk feeds
     * ffmpeg N simultaneous `-loop 1 -i image.jpg` inputs, all decoded into
     * raw frame buffers at native resolution — peak memory scales with
     * N × pixels. Smaller chunks for higher-res sources keep peak RSS
     * bounded regardless of album length.
     */
    private function resolveChunkSize(int $maxPixels): int {
        if ($maxPixels <= 0) {
            return 12;
        }
        if ($maxPixels <= 8_000_000) {
            return 16;
        }
        if ($maxPixels <= 16_000_000) {
            return 12;
        }
        if ($maxPixels <= 32_000_000) {
            return 8;
        }
        return 4;
    }

    /**
     * Standard ffmpeg command prefix. Caps decoder, filter, and encoder
     * threads to 2 each — left at ffmpeg's default of nproc, concurrent
     * full-resolution frame buffers in zoompan/xfade graphs have OOM-killed
     * or frozen the host on multi-core servers.
     *
     * @return array<int,string>
     */
    private function ffmpegBaseCmd(string $logLevel): array {
        return [
            'ffmpeg', '-y', '-hide_banner', '-loglevel', $logLevel,
            '-threads', '2',
            '-filter_threads', '2',
            '-filter_complex_threads', '2',
        ];
    }

    private function formatFloat(float $value): string {
        return number_format($value, 6, '.', '');
    }

    private function determineOutputHeight(int $width): int {
        $height = (int) round($width * 9 / 16);
        return $this->makeEven(max(2, $height));
    }

    private function emitProgress(?callable $outputHandler, string $message): void {
        if ($outputHandler === null) {
            return;
        }
        $outputHandler(Process::OUT, $message . PHP_EOL);
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
     * 4-phase Ken Burns pan/zoom. Phase is determined by $index % 4:
     *   0: pan right, 1: pan left, 2: pan down, 3: pan up. Zoom 1.0 -> 1.1.
     * @return array{z:string,x:string,y:string}
     */
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

    private function sanitizeFileName(string $fileName): string {
        $fileName = str_replace(['\\', '/'], '-', $fileName);
        $fileName = preg_replace('/[^A-Za-z0-9\.\-_ ]+/', '', $fileName) ?? '';
        $fileName = trim($fileName);
        // Collapse consecutive spaces
        $fileName = preg_replace('/\s+/', ' ', $fileName) ?? $fileName;
        return $fileName;
    }

    private function determineFileName(?string $preferredFileName, string $fallbackPrefix = 'Journey'): string {
        $fallback = sprintf('%s-%s.mp4', $fallbackPrefix, date('Ymd-His'));
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

    /**
     * Copy the temporary rendered file into the user's Documents/Journeys Movies/
     * folder. The using class must have a private IRootFolder $rootFolder property.
     *
     * @param string $user
     * @param string $tmpOut Absolute filesystem path of the rendered mp4
     * @param string|null $preferredFileName Suggested filename (sanitized, .mp4 appended if missing)
     * @param string $fallbackPrefix Prefix used when no preferred name is given
     *                               (portrait: "Journey", landscape: "Journey-Landscape")
     * @return string Virtual path inside the user's Nextcloud storage
     */
    private function persistToUserFiles(string $user, string $tmpOut, ?string $preferredFileName, string $fallbackPrefix = 'Journey'): string {
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

        $fileName = $this->determineFileName($preferredFileName, $fallbackPrefix);
        // Always append timestamp to ensure unique filename and avoid conflicts
        $baseName = preg_replace('/\.mp4$/i', '', $fileName);
        $fileName = $baseName . ' ' . date('Ymd-His') . '.mp4';

        // Stream, never read into a string: a long journey renders to several
        // hundred MB and blew PHP's memory_limit, leaving a 0-byte file behind.
        $source = @fopen($tmpOut, 'rb');
        if ($source === false) {
            throw new \RuntimeException('Failed to read temporary video output');
        }
        $destFile = $movies->newFile($fileName);
        try {
            $destFile->putContent($source);
        } catch (\Throwable $e) {
            try {
                $destFile->delete();
            } catch (\Throwable) {}
            throw $e;
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }
        return '/Documents/Journeys Movies/' . $fileName;
    }

    /**
     * Append one drawtext per location group to $parts (mutating it). Returns
     * the final label after subtitles are applied (== $inputLabel when no
     * usable groups exist). Required member: $this->titleFormatter.
     *
     * @param array<int,string>  $parts            ffmpeg filter parts (mutated)
     * @param array<int,?string> $segmentSubtitles per-segment subtitle text in render order
     * @param array<int,float>   $segmentHolds     per-segment visible seconds in render order
     */
    private function appendGroupSubtitles(
        array &$parts,
        string $inputLabel,
        array $segmentSubtitles,
        array $segmentHolds,
        int $width,
        int $shadowOffset = 2
    ): string {
        if (count($segmentSubtitles) === 0) {
            return $inputLabel;
        }
        // Run-length encode contiguous identical subtitles
        $groups = [];
        $count = count($segmentSubtitles);
        $start = 0;
        for ($i = 1; $i <= $count; $i++) {
            $atEnd = $i === $count;
            $cur = $atEnd ? null : $segmentSubtitles[$i];
            $prev = $segmentSubtitles[$start];
            if ($atEnd || $cur !== $prev) {
                if (is_string($prev) && $prev !== '') {
                    $groups[] = ['startIdx' => $start, 'length' => $i - $start, 'text' => $prev];
                }
                $start = $i;
            }
        }
        if (count($groups) === 0) {
            return $inputLabel;
        }
        $current = $inputLabel;
        foreach ($groups as $gi => $g) {
            $startTime = array_sum(array_slice($segmentHolds, 0, $g['startIdx']));
            $available = array_sum(array_slice($segmentHolds, $g['startIdx'], $g['length']));
            $targetDuration = min(5.0, $available * 0.9);
            // Need room for fade in + fade out. Skip vanishingly short groups.
            if ($targetDuration < 1.2) {
                continue;
            }
            $formatted = $this->titleFormatter->formatSubtitleForVideo($g['text'], $width);
            $outLabel = sprintf('subg%d', $gi);
            $parts[] = $this->titleFormatter->buildTimedSubtitleDrawtextFilter(
                $current,
                $outLabel,
                $formatted['text'],
                $formatted['fontSize'],
                $startTime,
                $targetDuration,
                $shadowOffset,
            );
            $current = $outLabel;
        }
        return $current;
    }

    private function isClipFile(string $path): bool {
        $lower = strtolower($path);
        return str_ends_with($lower, '.mp4') || str_ends_with($lower, '.mov');
    }

    /**
     * Snap a duration to whole frames. Chunks are rendered separately and
     * stitched with xfade at offsets computed from these durations; any
     * sub-frame remainder is lost per chunk and would accumulate into a
     * visible drift between clip audio (placed on the global timeline) and
     * clip picture.
     */
    private function snapToFrames(float $seconds, int $fps): float {
        return max(1, (int)round($seconds * $fps)) / $fps;
    }

    /**
     * Visible seconds per segment: clips carry their own length, everything
     * else holds for the configured per-image duration.
     *
     * @param array<int,array<string,mixed>> $segments
     * @return array<int,float>
     */
    private function segmentHolds(array $segments, float $holdDuration): array {
        return array_map(
            static fn(array $seg): float => (float)($seg['duration'] ?? $holdDuration),
            $segments,
        );
    }

    /**
     * Visible clip length, frame-aligned so it lands on the same grid as the
     * rest of the timeline.
     */
    private function clipHoldDuration(string $path, int $fps): float {
        $duration = $this->probeVideoDuration($path);
        if ($duration <= 0.0) {
            return 0.0;
        }
        return $this->snapToFrames(min($duration, VideoClipExtractor::CLIP_SECONDS), $fps);
    }

    /**
     * @return array{0:int,1:int}
     */
    private function probeVideoSize(string $path): array {
        try {
            $proc = new Process(['ffprobe', '-v', 'error', '-select_streams', 'v:0', '-show_entries', 'stream=width,height', '-of', 'csv=p=0:s=x', $path]);
            $proc->setTimeout(10);
            $proc->run();
            if ($proc->isSuccessful() && preg_match('/^(\d+)x(\d+)/', trim($proc->getOutput()), $m)) {
                return [(int)$m[1], (int)$m[2]];
            }
        } catch (\Throwable) {}
        return [0, 0];
    }

    /**
     * Motion-photo trailers are time-stretched (0.5x-2x) towards the hold and
     * then frozen on their last frame until the segment's xfade has finished.
     *
     * @return array{stretch:float,padFrames:int}
     */
    private function motionTiming(string $path, float $holdDuration, float $segmentSeconds, int $fps): array {
        $duration = $this->probeVideoDuration($path);
        if ($duration <= 0.0) {
            return ['stretch' => 1.0, 'padFrames' => (int)ceil($segmentSeconds * $fps)];
        }
        $stretch = max(0.5, min(2.0, $holdDuration / $duration));
        return [
            'stretch' => $stretch,
            'padFrames' => max(0, (int)ceil(($segmentSeconds - $duration * $stretch) * $fps)),
        ];
    }

    /**
     * The stretch happens on the input (`-itsscale`), not with setpts in the
     * graph: tpad pads from the stream's end timestamp, which setpts (and
     * trim) after the fps filter leave behind on the unstretched timeline, so
     * the freeze frames came out in the past and were dropped. The segment then
     * ended where its outgoing xfade starts and ffmpeg 7 skipped the first
     * seconds of whatever followed.
     *
     * @return array<int,string>
     */
    private function motionInputArgs(string $path, float $stretch): array {
        return ['-itsscale', $this->formatFloat($stretch), '-i', $path];
    }

    private function buildMotionFilter(int $inputIndex, string $outLabel, int $width, int $height, int $fps, int $padFrames): string {
        return sprintf(
            '[%1$d:v]setpts=PTS-STARTPTS,scale=%2$d:%3$d:force_original_aspect_ratio=increase,crop=%2$d:%3$d,fps=%4$d,setsar=1,' .
            'tpad=stop_mode=clone:stop=%5$d,settb=AVTB,fps=%4$d[%6$s]',
            $inputIndex,
            $width,
            $height,
            $fps,
            $padFrames,
            $outLabel,
        );
    }

    /**
     * @return array<int,string>
     */
    private function clipInputArgs(string $path, float $clipSeconds): array {
        return ['-t', $this->formatFloat($clipSeconds), '-i', $path];
    }

    /**
     * Mean level of the clip's sound in dB, or null when it has none.
     */
    private function measureMeanVolume(string $path, float $seconds): ?float {
        try {
            $proc = new Process(['ffmpeg', '-hide_banner', '-nostats', '-t', $this->formatFloat($seconds), '-i', $path, '-map', '0:a:0', '-af', 'volumedetect', '-f', 'null', '-']);
            $proc->setTimeout(30);
            $proc->run();
            if ($proc->isSuccessful() && preg_match('/mean_volume:\s*(-?[\d.]+|-inf) dB/', $proc->getErrorOutput(), $m) && $m[1] !== '-inf') {
                return (float)$m[1];
            }
        } catch (\Throwable) {}
        return null;
    }

    /**
     * Filter chain for a user video clip: plays at native speed (so its own
     * sound stays in sync), then holds the last frame for $padSeconds to feed
     * the outgoing xfade. A clip whose aspect ratio differs from the canvas is
     * fitted over a blurred fill of itself instead of being cropped to a sliver.
     *
     * The input must already be limited with `-t` (see clipInputArgs): a
     * `trim` inside the graph ends the stream in a way tpad never pads, so the
     * clip would stop exactly where its outgoing xfade starts, and ffmpeg 7
     * then collapses the rest of the xfade chain onto the last segment.
     */
    private function buildClipFilter(int $inputIndex, string $outLabel, int $width, int $height, int $fps, string $path, float $padSeconds): string {
        $head = sprintf(
            '[%1$d:v]setpts=PTS-STARTPTS,fps=%2$d,setsar=1',
            $inputIndex,
            $fps,
        );
        $tail = sprintf(
            'tpad=stop_mode=clone:stop_duration=%1$s,settb=AVTB,fps=%2$d[%3$s]',
            $this->formatFloat($padSeconds),
            $fps,
            $outLabel,
        );

        [$w, $h] = $this->probeVideoSize($path);
        $canvasRatio = $width / max(1, $height);
        $clipRatio = ($w > 0 && $h > 0) ? $w / $h : $canvasRatio;
        if (abs($clipRatio - $canvasRatio) / $canvasRatio < 0.15) {
            return sprintf(
                '%1$s,scale=%2$d:%3$d:force_original_aspect_ratio=increase,crop=%2$d:%3$d,%4$s',
                $head, $width, $height, $tail,
            );
        }

        $smallW = $this->makeEven(max(16, intdiv($width, 8)));
        $smallH = $this->makeEven(max(16, intdiv($height, 8)));
        return sprintf(
            '%1$s,split=2[%2$s_a][%2$s_b];' .
            '[%2$s_a]scale=%5$d:%6$d:force_original_aspect_ratio=increase,crop=%5$d:%6$d,gblur=sigma=6,scale=%3$d:%4$d,eq=brightness=-0.08[%2$s_bg];' .
            '[%2$s_b]scale=%3$d:%4$d:force_original_aspect_ratio=decrease,setsar=1[%2$s_fg];' .
            '[%2$s_bg][%2$s_fg]overlay=(W-w)/2:(H-h)/2,setsar=1,%7$s',
            $head, $outLabel, $width, $height, $smallW, $smallH, $tail,
        );
    }

    /**
     * Put the soundtrack under the finished picture: background music (ducked
     * while a clip plays) plus each clip's own sound at the clip's position on
     * the timeline. Clip start times come from the same frame-aligned holds
     * that placed the clips in the picture, which is what keeps them in sync.
     *
     * @param array<int,array{path:string,start:float,duration:float,gainDb:float}> $clipAudio
     */
    private function muxSoundtrack(
        string $videoPath,
        ?string $musicTrack,
        array $clipAudio,
        float $totalDuration,
        ?callable $outputHandler,
        bool $verbose,
        string $finalOutputPath,
    ): string {
        $logLevel = $verbose ? 'info' : 'error';
        $cmd = $this->ffmpegBaseCmd($logLevel);
        $cmd[] = '-i';
        $cmd[] = $videoPath;

        $parts = [];
        $mixLabels = [];
        $nextInput = 1;
        $total = $this->formatFloat($totalDuration);

        if ($musicTrack !== null && is_file($musicTrack)) {
            array_push($cmd, '-stream_loop', '-1', '-i', $musicTrack);
            $duck = empty($clipAudio) ? '' : sprintf(',volume=\'%s\':eval=frame', $this->buildDuckExpression($clipAudio));
            $parts[] = sprintf('[%d:a]atrim=0:%s,asetpts=PTS-STARTPTS%s[music]', $nextInput, $total, $duck);
            $mixLabels[] = '[music]';
            $nextInput++;
        }

        foreach ($clipAudio as $i => $clip) {
            array_push($cmd, '-i', $clip['path']);
            $duration = $clip['duration'];
            $edge = min(self::CLIP_AUDIO_FADE_SECONDS, $duration / 4);
            $delayMs = (int)round($clip['start'] * 1000);
            $parts[] = sprintf(
                '[%1$d:a]atrim=0:%2$s,asetpts=PTS-STARTPTS,aformat=sample_rates=48000:channel_layouts=stereo,' .
                'volume=%7$sdB,afade=t=in:d=%3$s,afade=t=out:st=%4$s:d=%3$s,adelay=%5$d:all=1[clip%6$d]',
                $nextInput,
                $this->formatFloat($duration),
                $this->formatFloat($edge),
                $this->formatFloat(max(0.0, $duration - $edge)),
                $delayMs,
                $i,
                $this->formatFloat($clip['gainDb']),
            );
            $mixLabels[] = sprintf('[clip%d]', $i);
            $nextInput++;
        }

        if (empty($mixLabels)) {
            rename($videoPath, $finalOutputPath);
            return $finalOutputPath;
        }

        $fadeDur = min(5.0, max(0.5, $totalDuration * 0.08));
        $fadeStart = max(0.0, $totalDuration - $fadeDur);
        $parts[] = sprintf(
            '%1$samix=inputs=%2$d:normalize=0:duration=longest,aformat=sample_rates=48000:channel_layouts=stereo,' .
            'apad,atrim=0:%3$s,afade=t=out:st=%4$s:d=%5$s,alimiter=limit=0.95[aout]',
            implode('', $mixLabels),
            count($mixLabels),
            $total,
            $this->formatFloat($fadeStart),
            $this->formatFloat($fadeDur),
        );

        $cmd = array_merge($cmd, [
            '-filter_complex', implode(';', $parts),
            '-map', '0:v:0',
            '-map', '[aout]',
            '-c:v', 'copy',
            '-c:a', 'aac',
            '-b:a', '192k',
            '-movflags', '+faststart',
            $finalOutputPath,
        ]);

        $outDir = dirname($finalOutputPath);
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

        @unlink($videoPath);
        return $finalOutputPath;
    }

    /**
     * Music gain over time: 0 dB between clips, DUCKED_MUSIC_DB under a clip,
     * with a ramp that is linear in dB (so the dip sounds even) across
     * DUCK_RAMP_SECONDS around each clip edge. Back-to-back clips stay ducked.
     *
     * @param array<int,array{start:float,duration:float}> $clipAudio
     */
    private function buildDuckExpression(array $clipAudio): string {
        $half = self::DUCK_RAMP_SECONDS / 2;
        $ramp = $this->formatFloat(self::DUCK_RAMP_SECONDS);
        $depth = '0';
        foreach ($clipAudio as $clip) {
            $in = $this->formatFloat($clip['start'] - $half);
            $out = $this->formatFloat($clip['start'] + $clip['duration'] + $half);
            $window = sprintf('min(clip((t-%1$s)/%3$s,0,1),clip((%2$s-t)/%3$s,0,1))', $in, $out, $ramp);
            $depth = sprintf('max(%s,%s)', $depth, $window);
        }
        return sprintf('pow(10,%s*%s/20)', $this->formatFloat(self::DUCKED_MUSIC_DB), $depth);
    }

    /**
     * Clip sound to place on the global timeline, given the final segment
     * order. Segments with no sound (stills, motion photos, silent clips) only
     * advance the clock.
     *
     * @param array<int,array<string,mixed>> $segments
     * @param array<int,float> $holds
     * @return array<int,array{path:string,start:float,duration:float,gainDb:float}>
     */
    private function collectClipAudio(array $segments, array $holds): array {
        $out = [];
        $clock = 0.0;
        foreach ($segments as $i => $seg) {
            if (($seg['type'] ?? '') === 'clip') {
                $path = (string)($seg['inputs'][0] ?? $seg['file'] ?? '');
                $meanDb = $path !== '' ? $this->measureMeanVolume($path, $holds[$i]) : null;
                if ($meanDb !== null) {
                    $out[] = [
                        'path' => $path,
                        'start' => $clock,
                        'duration' => $holds[$i],
                        'gainDb' => min(self::CLIP_MAX_GAIN_DB, self::CLIP_TARGET_MEAN_DB - $meanDb),
                    ];
                }
            }
            $clock += $holds[$i];
        }
        return $out;
    }
}
