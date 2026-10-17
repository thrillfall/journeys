<?php
namespace OCA\Journeys\Service;

use Symfony\Component\Process\Process;

/**
 * Cuts a short excerpt out of a user video so the renderers can treat it like
 * any other segment. The excerpt is re-encoded to a uniform shape (h264,
 * yuv420p, constant fps, long edge <= 1920, stereo 48 kHz AAC or no audio):
 * phone videos arrive as HEVC, VFR, rotated or 4K, and feeding those straight
 * into the xfade graph either breaks it or drifts the clip audio against the
 * picture.
 */
class VideoClipExtractor {
    public const CLIP_SECONDS = 4.0;
    private const MIN_CLIP_SECONDS = 1.5;
    private const MAX_LONG_EDGE = 1920;
    private const FPS = 30;

    /**
     * @return bool true when $destinationPath holds a playable clip
     */
    public function extract(string $sourcePath, string $destinationPath): bool {
        $duration = $this->probeDuration($sourcePath);
        if ($duration < self::MIN_CLIP_SECONDS) {
            return false;
        }

        $length = min(self::CLIP_SECONDS, $duration);
        // Centre of the recording: the first second is usually framing, the last stopping.
        $start = max(0.0, ($duration - $length) / 2.0);

        $cmd = [
            'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
            '-threads', '2',
            '-ss', $this->formatFloat($start),
            '-i', $sourcePath,
            '-t', $this->formatFloat($length),
            '-map', '0:v:0',
            '-map', '0:a:0?',
            '-vf', sprintf(
                "scale=w='min(%1\$d,iw)':h='min(%1\$d,ih)':force_original_aspect_ratio=decrease:force_divisible_by=2,fps=%2\$d:start_time=0,setsar=1,format=yuv420p",
                self::MAX_LONG_EDGE,
                self::FPS,
            ),
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20',
            '-af', 'aresample=48000:async=1,aformat=channel_layouts=stereo',
            '-c:a', 'aac', '-b:a', '160k',
            '-movflags', '+faststart',
            $destinationPath,
        ];

        $process = new Process($cmd);
        $process->setTimeout(300);
        try {
            $process->run();
        } catch (\Throwable) {
            @unlink($destinationPath);
            return false;
        }

        if (!$process->isSuccessful() || !is_file($destinationPath) || (int)@filesize($destinationPath) === 0) {
            @unlink($destinationPath);
            return false;
        }
        return true;
    }

    private function probeDuration(string $path): float {
        $process = new Process(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=nw=1:nk=1', $path]);
        $process->setTimeout(30);
        try {
            $process->run();
        } catch (\Throwable) {
            return 0.0;
        }
        $value = (float)trim($process->getOutput());
        return ($process->isSuccessful() && is_finite($value)) ? $value : 0.0;
    }

    private function formatFloat(float $value): string {
        return number_format($value, 3, '.', '');
    }
}
