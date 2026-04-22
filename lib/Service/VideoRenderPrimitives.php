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

    private function makeEven(int $value): int {
        return ($value % 2 === 0) ? $value : $value + 1;
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

        $destFile = $movies->newFile($fileName);
        $data = @file_get_contents($tmpOut);
        if ($data === false) {
            throw new \RuntimeException('Failed to read temporary video output');
        }
        $destFile->putContent($data);
        return '/Documents/Journeys Movies/' . $fileName;
    }
}
