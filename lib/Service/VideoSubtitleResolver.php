<?php
namespace OCA\Journeys\Service;

use OCA\Journeys\Model\Image;

/**
 * Resolves a fine-grained location label (suburb / neighborhood / city) for each
 * image in a sequence, then smooths the sequence so isolated 1-image flips do
 * not produce 1-frame subtitle blips during rendering.
 *
 * Returns one entry per input image; null entries mean "no subtitle".
 */
class VideoSubtitleResolver {
    /** @var array<string,?string> */
    private array $cache = [];

    public function __construct(
        private SimplePlaceResolver $placeResolver,
    ) {}

    /**
     * Build a basename -> subtitle map ready to hand to the renderer. Pass the
     * file paths the preparer produced and the parallel Image[] (preparer's
     * 'images' array). Empty arrays are returned when the inputs disagree.
     *
     * @param string[] $filePaths
     * @param Image[] $images
     * @return array<string,?string>
     */
    public function buildBasenameMap(array $filePaths, array $images): array {
        if (count($filePaths) === 0 || count($filePaths) !== count($images)) {
            return [];
        }
        $names = $this->resolveForImages($images);
        // If the whole journey resolves to one (or zero) place names there is
        // nothing meaningful to caption — skip subtitles entirely.
        $distinct = array_unique(array_filter($names, static fn($n) => is_string($n) && $n !== ''));
        if (count($distinct) < 2) {
            return [];
        }
        $map = [];
        foreach ($filePaths as $i => $path) {
            $basename = pathinfo($path, PATHINFO_FILENAME);
            if ($basename === '') {
                continue;
            }
            $map[$basename] = $names[$i] ?? null;
        }
        return $map;
    }

    /**
     * @param Image[] $images
     * @return array<int,?string> parallel to $images
     */
    public function resolveForImages(array $images): array {
        $names = [];
        foreach ($images as $img) {
            $names[] = $img instanceof Image ? $this->resolveOne($img) : null;
        }
        return self::smooth($names, 2);
    }

    private function resolveOne(Image $img): ?string {
        if ($img->lat === null || $img->lon === null) {
            return null;
        }
        $lat = (float)$img->lat;
        $lon = (float)$img->lon;
        // Round to ~11m grid to share lookups across nearby photos
        $key = sprintf('%.4f,%.4f', $lat, $lon);
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }
        $places = $this->placeResolver->queryPoint($lat, $lon, $img->fileid ?? null);
        $name = $this->pickFinestName($places);
        $this->cache[$key] = $name;
        return $name;
    }

    /**
     * @param array<int,array{osm_id:mixed,admin_level:mixed,name:mixed}> $places
     */
    private function pickFinestName(array $places): ?string {
        if (empty($places)) {
            return null;
        }
        // Prefer the highest admin_level (most specific). OSM admin levels:
        // 2=country, 4=state, 6=county, 7=metropolitan area, 8=city/town,
        // 9-12=districts/suburbs/neighborhoods. We allow >=8 but prefer >=9.
        usort($places, static fn(array $a, array $b) => (int)$b['admin_level'] <=> (int)$a['admin_level']);
        foreach ($places as $place) {
            if ((int)$place['admin_level'] < 8) {
                continue;
            }
            $name = isset($place['name']) ? trim((string)$place['name']) : '';
            if ($name !== '') {
                return $name;
            }
        }
        // Fallback: any named entry, broadest available
        foreach ($places as $place) {
            $name = isset($place['name']) ? trim((string)$place['name']) : '';
            if ($name !== '') {
                return $name;
            }
        }
        return null;
    }

    /**
     * Replace runs of non-null names shorter than $minRun with a neighboring
     * name (preferring matching prev==next, otherwise the longer neighbor),
     * suppressing them to null when no usable neighbor exists. Null entries
     * (no GPS) always stay null.
     *
     * Public + static so it can be unit-tested without a DB.
     *
     * @param array<int,?string> $names
     * @return array<int,?string>
     */
    public static function smooth(array $names, int $minRun = 2): array {
        if ($minRun <= 1 || count($names) === 0) {
            return $names;
        }
        // Run-length encode
        $runs = [];
        foreach ($names as $n) {
            $last = $runs === [] ? null : $runs[count($runs) - 1];
            if ($last !== null && $last['name'] === $n) {
                $runs[count($runs) - 1]['length']++;
            } else {
                $runs[] = ['name' => $n, 'length' => 1];
            }
        }
        // Smooth short non-null runs
        $count = count($runs);
        for ($i = 0; $i < $count; $i++) {
            $run = $runs[$i];
            if ($run['name'] === null || $run['length'] >= $minRun) {
                continue;
            }
            $prev = $i > 0 ? $runs[$i - 1] : null;
            $next = $i < $count - 1 ? $runs[$i + 1] : null;
            // No neighbors at all → this run is the whole timeline; keep as-is.
            if ($prev === null && $next === null) {
                continue;
            }
            $newName = null;
            if ($prev !== null && $next !== null && $prev['name'] !== null && $prev['name'] === $next['name']) {
                $newName = $prev['name'];
            } elseif ($prev !== null && $prev['name'] !== null && ($next === null || $prev['length'] >= ($next['length'] ?? 0))) {
                $newName = $prev['name'];
            } elseif ($next !== null && $next['name'] !== null) {
                $newName = $next['name'];
            }
            $runs[$i]['name'] = $newName;
        }
        // Expand back
        $out = [];
        foreach ($runs as $run) {
            for ($k = 0; $k < $run['length']; $k++) {
                $out[] = $run['name'];
            }
        }
        return $out;
    }
}
