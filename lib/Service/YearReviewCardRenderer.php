<?php
namespace OCA\Journeys\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Pre-renders the designed parts of a year-in-review video as short silent
 * clips at the exact output size: the opening montage, a map card before each
 * journey and the closing card with the year's numbers. The video renderer
 * then plays them like any other clip, so it needs no card logic of its own.
 *
 * Every clip stays within VideoClipExtractor::CLIP_SECONDS, the longest clip
 * the renderer plays in full.
 */
class YearReviewCardRenderer {
    public const INTRO_SECONDS = 4.0;
    public const MAP_SECONDS = 3.0;
    public const STATS_SECONDS = 4.0;
    public const MONTAGE_FRAME_SECONDS = 0.3;
    public const MONTAGE_MAX_PHOTOS = 8;

    private const BOLD = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
    private const REGULAR = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';

    public function __construct(
        private StaticRouteMapService $maps,
        private VideoTitleFormatter $formatter,
        private LoggerInterface $logger,
    ) {}

    /**
     * Quick cuts through the year, then the year itself over a blurred photo.
     *
     * @param string[] $stills
     */
    public function intro(string $dir, array $stills, string $year, int $w, int $h, int $fps): ?string {
        $stills = array_slice(array_values($stills), 0, self::MONTAGE_MAX_PHOTOS);
        if ($stills === []) {
            return null;
        }
        $titleSeconds = self::INTRO_SECONDS - count($stills) * self::MONTAGE_FRAME_SECONDS;
        $inputs = [];
        $parts = [];
        foreach ($stills as $i => $still) {
            array_push($inputs, '-loop', '1', '-framerate', (string)$fps, '-t', $this->num(self::MONTAGE_FRAME_SECONDS), '-i', $still);
            $parts[] = sprintf('[%d:v]%s,format=yuv420p[m%d]', $i, $this->fill($w, $h, $fps), $i);
        }
        $last = count($stills);
        array_push($inputs, '-loop', '1', '-framerate', (string)$fps, '-t', $this->num($titleSeconds), '-i', $stills[$last - 1]);
        $parts[] = sprintf(
            '[%d:v]%s,%s,%s,format=yuv420p[title]',
            $last,
            $this->fill($w, $h, $fps),
            $this->backdrop(),
            $this->text($year, self::BOLD, (int)(min($w, $h) * 0.28), '(h-text_h)/2', 0.1, 0.4),
        );
        $labels = '';
        for ($i = 0; $i < $last; $i++) {
            $labels .= sprintf('[m%d]', $i);
        }
        $parts[] = sprintf('%s[title]concat=n=%d:v=1:a=0[out]', $labels, $last + 1);

        return $this->encode($dir . '/yr-intro.mp4', $inputs, $parts, self::INTRO_SECONDS, $fps);
    }

    /**
     * Where the journey went, over a blurred photo of it, with its name and dates.
     *
     * @param array<int,array{lat:float,lon:float}> $points
     */
    public function mapCard(string $dir, int $index, string $background, array $points, string $title, string $subtitle, int $w, int $h, int $fps): ?string {
        $png = $this->maps->pngForPoints($points);
        if ($png === null) {
            return null;
        }
        $mapPath = sprintf('%s/yr-map-%02d.png', $dir, $index);
        if (file_put_contents($mapPath, $png) === false) {
            return null;
        }
        [$mapW, $mapH] = $this->imageSize($mapPath);
        if ($mapW <= 0 || $mapH <= 0) {
            return null;
        }

        $landscape = $w > $h;
        $boxW = $landscape ? $w * 0.62 : $w * 0.88;
        $boxH = $landscape ? $h * 0.58 : $h * 0.42;
        $scale = min($boxW / $mapW, $boxH / $mapH);
        $mw = $this->even((int)($mapW * $scale));
        $mh = $this->even((int)($mapH * $scale));
        $border = max(4, (int)($w * 0.006));
        $mapTop = (int)($landscape ? $h * 0.12 : $h * 0.22);
        [$titleLines, $titleSize] = self::fitText($title, (int)(min($w, $h) * 0.075), $w * 0.88, 2);
        [$subLines, $subSize] = self::fitText($subtitle, (int)(min($w, $h) * 0.042), $w * 0.88, 1);
        $titleY = $mapTop + $mh + 2 * $border + (int)($titleSize * 0.7);
        $subY = $titleY + (int)($titleSize * 1.25 * count($titleLines)) + (int)($subSize * 0.4);

        $inputs = [
            '-loop', '1', '-framerate', (string)$fps, '-t', $this->num(self::MAP_SECONDS), '-i', $background,
            '-loop', '1', '-framerate', (string)$fps, '-t', $this->num(self::MAP_SECONDS), '-i', $mapPath,
        ];
        $parts = [
            sprintf('[0:v]%s,%s[bg]', $this->fill($w, $h, $fps), $this->backdrop()),
            sprintf(
                '[1:v]scale=%1$d:%2$d,pad=%3$d:%4$d:%5$d:%5$d:white,format=rgba,fade=t=in:st=0:d=0.4:alpha=1[map]',
                $mw, $mh, $mw + 2 * $border, $mh + 2 * $border, $border,
            ),
            sprintf(
                '[bg][map]overlay=x=(W-w)/2:y=%d:shortest=1,%s,%s,format=yuv420p[out]',
                $mapTop,
                $this->lines($titleLines, self::BOLD, $titleSize, $titleY, 0.3),
                $this->lines($subLines, self::REGULAR, $subSize, $subY, 0.5),
            ),
        ];
        return $this->encode(sprintf('%s/yr-map-%02d.mp4', $dir, $index), $inputs, $parts, self::MAP_SECONDS, $fps);
    }

    /**
     * The year in numbers; each line fades in after the one before.
     *
     * @param string[] $lines
     */
    public function statsCard(string $dir, string $background, string $headline, array $lines, int $w, int $h, int $fps): ?string {
        $headSize = (int)(min($w, $h) * 0.2);
        $lineSize = (int)(min($w, $h) * 0.062);
        $landscape = $w > $h;
        $headY = (int)($landscape ? $h * 0.12 : $h * 0.2);
        $firstLineY = $headY + (int)($headSize * 1.5);
        $filters = [
            $this->fill($w, $h, $fps),
            $this->backdrop(0.28),
            $this->text($headline, self::BOLD, $headSize, (string)$headY, 0.1, 0.4),
        ];
        foreach (array_values($lines) as $i => $line) {
            [$fitted, $size] = self::fitText($line, $lineSize, $w * 0.88, 1);
            $y = $firstLineY + (int)($i * $lineSize * 1.9) + intdiv($lineSize - $size, 2);
            $filters[] = $this->lines($fitted, self::BOLD, $size, $y, 0.6 + $i * 0.35);
        }
        $inputs = ['-loop', '1', '-framerate', (string)$fps, '-t', $this->num(self::STATS_SECONDS), '-i', $background];
        $parts = [sprintf('[0:v]%s,format=yuv420p[out]', implode(',', $filters))];
        return $this->encode($dir . '/yr-stats.mp4', $inputs, $parts, self::STATS_SECONDS, $fps);
    }

    /**
     * Wrap into at most $maxLines lines, then shrink the font until the
     * longest line fits $maxWidth. DejaVu Sans Bold averages about 0.66 em per
     * character in mixed case, which errs on the side of fitting.
     *
     * @return array{0:string[],1:int} lines and font size
     */
    public static function fitText(string $text, int $size, float $maxWidth, int $maxLines): array {
        $text = trim(preg_replace('/\s*\/\s*/', ' / ', $text) ?? $text);
        $perLine = max(1, (int)floor($maxWidth / ($size * 0.66)));
        $lines = [$text];
        if ($maxLines > 1 && mb_strlen($text) > $perLine) {
            $words = preg_split('/\s+/', $text) ?: [$text];
            $lines = [''];
            foreach ($words as $word) {
                $current = $lines[count($lines) - 1];
                $candidate = $current === '' ? $word : $current . ' ' . $word;
                if ($current !== '' && mb_strlen($candidate) > $perLine && count($lines) < $maxLines) {
                    $lines[] = $word;
                } else {
                    $lines[count($lines) - 1] = $candidate;
                }
            }
        }
        $longest = max(array_map('mb_strlen', $lines));
        $fitted = (int)min($size, floor($maxWidth / max(1, $longest * 0.66)));
        return [$lines, max(12, $fitted)];
    }

    /**
     * One centred drawtext per line; drawtext would otherwise left-align
     * the lines of a multi-line block.
     *
     * @param string[] $lines
     */
    private function lines(array $lines, string $font, int $size, int $top, float $fadeInAt): string {
        $out = [];
        foreach (array_values($lines) as $i => $line) {
            $out[] = $this->text($line, $font, $size, (string)($top + (int)($i * $size * 1.25)), $fadeInAt, 0.4);
        }
        return implode(',', $out);
    }

    private function fill(int $w, int $h, int $fps): string {
        return sprintf('scale=%1$d:%2$d:force_original_aspect_ratio=increase,crop=%1$d:%2$d,setsar=1,fps=%3$d', $w, $h, $fps);
    }

    private function backdrop(float $darken = 0.22): string {
        return sprintf('gblur=sigma=28,eq=brightness=-%s:saturation=0.85', $this->num($darken));
    }

    private function text(string $text, string $font, int $size, string $y, float $fadeInAt, float $fadeSeconds): string {
        return sprintf(
            "drawtext=fontfile=%s:expansion=none:text='%s':fontcolor=white:fontsize=%d:line_spacing=%d:" .
            "x=(w-text_w)/2:y=%s:shadowcolor=black@0.6:shadowx=3:shadowy=3:" .
            "alpha='if(lt(t,%s),0,min(1,(t-%s)/%s))'",
            $font,
            $this->formatter->escapeForDrawtext($text),
            $size,
            (int)($size * 0.25),
            $y,
            $this->num($fadeInAt),
            $this->num($fadeInAt),
            $this->num($fadeSeconds),
        );
    }

    /**
     * @param string[] $inputs
     * @param string[] $parts the graph must end in [out]
     */
    private function encode(string $path, array $inputs, array $parts, float $seconds, int $fps): ?string {
        $cmd = array_merge(
            ['ffmpeg', '-y', '-hide_banner', '-loglevel', 'error', '-nostats', '-threads', '2'],
            $inputs,
            [
                '-filter_complex', implode(';', $parts),
                '-map', '[out]', '-an',
                '-t', $this->num($seconds), '-r', (string)$fps,
                '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '18', '-pix_fmt', 'yuv420p',
                $path,
            ],
        );
        $process = new Process($cmd);
        $process->setTimeout(180);
        $process->run();
        if (!$process->isSuccessful() || !is_file($path) || filesize($path) === 0) {
            $this->logger->warning('Journeys: year-review card failed', [
                'card' => basename($path),
                'error' => substr($process->getErrorOutput(), 0, 2000),
            ]);
            @unlink($path);
            return null;
        }
        return $path;
    }

    /** @return array{0:int,1:int} */
    private function imageSize(string $path): array {
        $info = @getimagesize($path);
        return is_array($info) ? [(int)$info[0], (int)$info[1]] : [0, 0];
    }

    private function even(int $v): int {
        return max(2, $v - ($v % 2));
    }

    private function num(float $v): string {
        return rtrim(rtrim(sprintf('%.3f', $v), '0'), '.');
    }
}
