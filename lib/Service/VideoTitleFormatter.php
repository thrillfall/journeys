<?php
namespace OCA\Journeys\Service;

class VideoTitleFormatter {
    /**
     * Calculate font size and max characters per line to fit text in target width
     *
     * @param int $videoWidth Width of the video in pixels
     * @param float $targetWidthPercent Percentage of video width to use (default 0.8 = 80%)
     * @return array{fontSize: int, maxCharsPerLine: int}
     */
    public function calculateTextMetrics(int $videoWidth, float $targetWidthPercent = 0.8): array {
        // Use 5% of video width as base font size (increased from 3%)
        $fontSize = max(32, (int)($videoWidth * 0.05));

        // Estimate average character width as 60% of font size (typical for bold fonts)
        $avgCharWidth = $fontSize * 0.6;

        // Calculate how many characters fit in target width
        $targetPixelWidth = $videoWidth * $targetWidthPercent;
        $maxCharsPerLine = max(10, (int)floor($targetPixelWidth / $avgCharWidth));

        return [
            'fontSize' => $fontSize,
            'maxCharsPerLine' => $maxCharsPerLine,
        ];
    }

    /**
     * Wrap long text by inserting line breaks at word boundaries or dashes
     *
     * @param string $text The text to wrap
     * @param int $maxCharsPerLine Maximum characters per line
     * @return string Text with line breaks inserted
     */
    public function wrapTextForDisplay(string $text, int $maxCharsPerLine = 25): string {
        if (mb_strlen($text) <= $maxCharsPerLine) {
            return $text;
        }

        // First, split by " - " to force breaks after dashes
        $segments = explode(' - ', $text);
        $allLines = [];

        foreach ($segments as $segIndex => $segment) {
            $words = preg_split('/\s+/', trim($segment));
            if ($words === false) {
                continue;
            }

            $currentLine = '';
            foreach ($words as $word) {
                $testLine = $currentLine === '' ? $word : $currentLine . ' ' . $word;
                if (mb_strlen($testLine) <= $maxCharsPerLine) {
                    $currentLine = $testLine;
                } else {
                    // Line would be too long, break here
                    if ($currentLine !== '') {
                        $allLines[] = $currentLine;
                    }
                    $currentLine = $word;
                }
            }

            // Add dash back to the end of this segment (except for last segment)
            if ($segIndex < count($segments) - 1) {
                $currentLine .= ' -';
            }

            if ($currentLine !== '') {
                $allLines[] = $currentLine;
            }
        }

        return implode("\n", $allLines);
    }

    /**
     * Escape text for FFmpeg drawtext filter
     *
     * @param string $text The text to escape
     * @return string Escaped text
     */
    public function escapeForDrawtext(string $text): string {
        return str_replace([':', '\'', '\\'], ['\\:', '\\\'', '\\\\'], $text);
    }

    /**
     * Format album name for video title overlay (all-in-one method)
     *
     * Calculates optimal font size and line wrapping based on video width,
     * wraps the text, and escapes it for FFmpeg drawtext filter.
     *
     * @param string $albumName The album name to format
     * @param int $videoWidth Width of the video in pixels
     * @param float $targetWidthPercent Percentage of video width to use (default 0.8 = 80%)
     * @return array{text: string, fontSize: int} Escaped text ready for FFmpeg and font size
     */
    public function formatForVideo(string $albumName, int $videoWidth, float $targetWidthPercent = 0.8): array {
        $metrics = $this->calculateTextMetrics($videoWidth, $targetWidthPercent);
        $wrappedText = $this->wrapTextForDisplay($albumName, $metrics['maxCharsPerLine']);
        $escapedText = $this->escapeForDrawtext($wrappedText);

        return [
            'text' => $escapedText,
            'fontSize' => $metrics['fontSize'],
        ];
    }

    /**
     * Build FFmpeg drawtext filter string for video title overlay
     *
     * Creates a complete FFmpeg filter with fade in/out animation for displaying
     * a title overlay on video segments.
     *
     * @param string $inputLabel FFmpeg filter input label
     * @param string $outputLabel FFmpeg filter output label
     * @param string $text Escaped text to display (use formatForVideo() or escapeForDrawtext())
     * @param int $fontSize Font size in pixels
     * @param float $duration Total duration the text should be visible (including fade)
     * @param int $shadowOffset Shadow offset in pixels (default 2)
     * @return string Complete FFmpeg drawtext filter string
     */
    public function buildDrawtextFilter(
        string $inputLabel,
        string $outputLabel,
        string $text,
        int $fontSize,
        float $duration = 4.0,
        int $shadowOffset = 2
    ): string {
        $fadeInDuration = 0.5;
        $fadeOutStart = $duration - 0.5;

        return sprintf(
            '[%1$s]drawtext=fontfile=/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf:' .
            'text=\'%2$s\':fontcolor=white:fontsize=%3$d:' .
            'x=(w-text_w)/2:y=(h-text_h)/2:' .
            'shadowcolor=black:shadowx=%7$d:shadowy=%7$d:' .
            'enable=\'between(t,0,%4$s)\':' .
            'alpha=\'if(lt(t,%8$s), t/%8$s, if(lt(t,%9$s), 1, if(lt(t,%4$s), (%4$s-t)/%8$s, 0)))\'[%6$s]',
            $inputLabel,
            $text,
            $fontSize,
            $this->formatFloat($duration),
            '', // placeholder
            $outputLabel,
            $shadowOffset,
            $this->formatFloat($fadeInDuration),
            $this->formatFloat($fadeOutStart)
        );
    }

    /**
     * Format subtitle text for video overlay (smaller font, narrower wrap).
     *
     * @return array{text: string, fontSize: int}
     */
    public function formatSubtitleForVideo(string $text, int $videoWidth): array {
        // Subtitle font ~3% of width, min 24px — smaller than the title.
        $fontSize = max(24, (int)($videoWidth * 0.03));
        $avgCharWidth = $fontSize * 0.6;
        $maxCharsPerLine = max(10, (int)floor(($videoWidth * 0.8) / $avgCharWidth));
        $wrapped = $this->wrapTextForDisplay($text, $maxCharsPerLine);
        return [
            'text' => $this->escapeForDrawtext($wrapped),
            'fontSize' => $fontSize,
        ];
    }

    /**
     * Build a bottom-anchored drawtext filter for a location-group subtitle,
     * timed in absolute timeline seconds. Applied to the post-xfade merged
     * stream so a single subtitle can span multiple segments (up to ~5s).
     */
    public function buildTimedSubtitleDrawtextFilter(
        string $inputLabel,
        string $outputLabel,
        string $text,
        int $fontSize,
        float $startTime,
        float $duration,
        int $shadowOffset = 2,
        float $fadeIn = 0.5,
        float $fadeOut = 0.5
    ): string {
        $fadeIn = max(0.05, $fadeIn);
        $fadeOut = max(0.05, $fadeOut);
        $endTime = $startTime + $duration;
        $holdEnd = max($startTime + $fadeIn, $endTime - $fadeOut);
        return sprintf(
            '[%1$s]drawtext=fontfile=/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf:' .
            'text=\'%2$s\':fontcolor=white:fontsize=%3$d:' .
            'x=(w-text_w)/2:y=h-text_h-(h*0.06):' .
            'shadowcolor=black:shadowx=%4$d:shadowy=%4$d:' .
            'enable=\'between(t,%5$s,%6$s)\':' .
            'alpha=\'if(lt(t,%7$s),(t-%5$s)/%8$s,if(lt(t,%9$s),1,(%6$s-t)/%10$s))\'[%11$s]',
            $inputLabel,
            $text,
            $fontSize,
            $shadowOffset,
            $this->formatFloat($startTime),
            $this->formatFloat($endTime),
            $this->formatFloat($startTime + $fadeIn),
            $this->formatFloat($fadeIn),
            $this->formatFloat($holdEnd),
            $this->formatFloat($fadeOut),
            $outputLabel,
        );
    }

    /**
     * Format float for FFmpeg filter (ensures proper decimal formatting)
     *
     * @param float $value The float value to format
     * @return string Formatted string
     */
    private function formatFloat(float $value): string {
        return number_format($value, 6, '.', '');
    }
}
