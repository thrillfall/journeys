<?php
namespace OCA\Journeys\Service;

class ClusterVideoMusicProvider {

    public function pickRandomTrack(): ?string {
        $configured = $this->getConfiguredTracks();
        if (!empty($configured)) {
            shuffle($configured);
            foreach ($configured as $url) {
                if (is_string($url) && $url !== '') {
                    $local = $this->ensureCachedLocal($url);
                    return $local ?? $url;
                }
            }
        }

        return null;
    }

    /**
     * @return array<int,string>
     */
    private function getConfiguredTracks(): array {
        return [
            'https://thrillfall.github.io/journeys/audio/1983%20inst%20mix%20ab%20oz_128kbps.mp3',
            'https://thrillfall.github.io/journeys/audio/baby%20jean%20audio%20moby%20mix%201_128kbps.mp3',
            'https://thrillfall.github.io/journeys/audio/dream%20a%20dream%20inst%20mix%20ab%20oz_128kbps.mp3',
            'https://thrillfall.github.io/journeys/audio/drive%20home%20now%20inst%20mix%20ab%20oz_128kbps.mp3',
            'https://thrillfall.github.io/journeys/audio/harder%20inst%20mix%20ab%20oz_128kbps.mp3',
            'https://thrillfall.github.io/journeys/audio/i%20can%20believe%20inst%20mix%20ab%20oz_128kbps.mp3',
            'https://thrillfall.github.io/journeys/audio/last%20of%20us%20inst%20mix%20ab%20oz_128kbps.mp3',
            'https://thrillfall.github.io/journeys/audio/more%20than%20a%20song%20ab%20oz_128kbps.mp3',
        ];
    }

    private function ensureCachedLocal(string $url): ?string {
        if (!(str_starts_with($url, 'http://') || str_starts_with($url, 'https://'))) {
            return null;
        }

        $baseDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'journeys-audio-cache';
        if (!is_dir($baseDir)) {
            @mkdir($baseDir, 0777, true);
        }

        $name = basename(parse_url($url, PHP_URL_PATH) ?: 'audio.mp3');
        if ($name === '' || stripos($name, '.mp3') === false) {
            $name = substr(hash('sha256', $url), 0, 16) . '.mp3';
        }
        $dest = $baseDir . DIRECTORY_SEPARATOR . $name;

        if (is_file($dest) && filesize($dest) > 102400) {
            return $dest;
        }

        $headers = [
            'User-Agent: Journeys/1.0 (+https://github.com/thrillfall/journeys)',
            'Accept: audio/mpeg,audio/*;q=0.9,*/*;q=0.1',
        ];
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 30,
                'follow_location' => 1,
                'ignore_errors' => true,
                'header' => implode("\r\n", $headers),
            ],
            'https' => [
                'method' => 'GET',
                'timeout' => 30,
                'follow_location' => 1,
                'ignore_errors' => true,
                'header' => implode("\r\n", $headers),
            ],
        ]);
        if (PHP_SAPI === 'cli') {
            @fwrite(STDOUT, sprintf("Downloading audio: %s\n", $url));
        }
        $in = @fopen($url, 'rb', false, $ctx);
        if ($in === false) {
            if (PHP_SAPI === 'cli') {
                @fwrite(STDOUT, "Warning: failed to open remote audio URL. Falling back to streaming.\n");
            }
            return null;
        }
        $tmp = $dest . '.part';
        $out = @fopen($tmp, 'wb');
        if ($out === false) {
            @fclose($in);
            if (PHP_SAPI === 'cli') {
                @fwrite(STDOUT, "Warning: failed to open cache file for writing.\n");
            }
            return null;
        }
        $bytes = @stream_copy_to_stream($in, $out);
        @fclose($out);
        @fclose($in);

        $statusCode = 0;
        $contentType = '';
        $contentLength = null;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $h, $m)) { $statusCode = (int)$m[1]; }
                if (stripos($h, 'Content-Type:') === 0) { $contentType = trim(substr($h, 13)); }
                if (stripos($h, 'Content-Length:') === 0) { $cl = trim(substr($h, 15)); if (ctype_digit($cl)) { $contentLength = (int)$cl; } }
            }
        }

        $okStatus = ($statusCode === 200);
        $isAudio = ($contentType === '' || stripos($contentType, 'audio/') === 0 || stripos($contentType, 'application/octet-stream') === 0);
        $sizeOk = (is_int($bytes) && $bytes > 102400 && ($contentLength === null || $bytes === $contentLength));

        if (!$okStatus || !$isAudio || !$sizeOk) {
            @unlink($tmp);
            if (PHP_SAPI === 'cli') {
                @fwrite(STDOUT, sprintf("Warning: audio download validation failed (status=%d, type=%s, bytes=%s). Falling back to streaming.\n", $statusCode, $contentType, is_int($bytes) ? (string)$bytes : '?'));
            }
            return null;
        }

        if (!@rename($tmp, $dest)) {
            @unlink($tmp);
            if (PHP_SAPI === 'cli') {
                @fwrite(STDOUT, "Warning: failed to finalize audio cache file.\n");
            }
            return null;
        }

        if (PHP_SAPI === 'cli') {
            $size = @filesize($dest);
            @fwrite(STDOUT, sprintf("Saved audio cache: %s (%s bytes)\n", $dest, is_int($size) ? (string)$size : '?'));
        }
        return $dest;
    }
}
