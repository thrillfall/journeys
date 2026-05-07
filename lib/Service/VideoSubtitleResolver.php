<?php
namespace OCA\Journeys\Service;

use OCA\Journeys\Model\Image;

/**
 * Resolves a fine-grained location label (suburb / neighborhood / city) for each
 * image in a sequence, then smooths the sequence so isolated 1-image flips do
 * not produce 1-frame subtitle blips during rendering.
 *
 * Per image we resolve both a "city" (admin_level 8 / 7 / 6) and a "suburb"
 * (most specific available, level >=8). If the journey spans 2+ distinct
 * cities we caption with the city name — this prevents Paris from being
 * labelled "13e arrondissement" or Frankfurt being labelled "Süd" on a
 * multi-city trip. Single-city trips fall back to the suburb so intra-city
 * movement still produces meaningful caption changes.
 *
 * Returns one entry per input image; null entries mean "no subtitle".
 */
class VideoSubtitleResolver {
    /** @var array<string,array{city:?string,suburb:?string}> */
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
        $pairs = [];
        foreach ($images as $img) {
            $pairs[] = $img instanceof Image
                ? $this->resolvePair($img)
                : ['city' => null, 'suburb' => null];
        }
        // If the journey spans 2+ distinct city-level names, caption by city —
        // otherwise the user sees suburbs of larger cities (e.g. "13e
        // arrondissement" instead of "Paris"). For single-city trips, fall
        // back to suburb so intra-city movement still varies the caption.
        $distinctCities = array_unique(array_filter(
            array_map(static fn(array $p) => $p['city'], $pairs),
            static fn($n) => is_string($n) && $n !== '',
        ));
        $useCity = count($distinctCities) >= 2;
        $names = [];
        foreach ($pairs as $p) {
            if ($useCity) {
                $names[] = $p['city'] ?? $p['suburb'];
            } else {
                $names[] = $p['suburb'] ?? $p['city'];
            }
        }
        return self::smooth($names, 2);
    }

    /**
     * @return array{city:?string,suburb:?string}
     */
    private function resolvePair(Image $img): array {
        if ($img->lat === null || $img->lon === null) {
            return ['city' => null, 'suburb' => null];
        }
        $lat = (float)$img->lat;
        $lon = (float)$img->lon;
        // Round to ~11m grid to share lookups across nearby photos
        $key = sprintf('%.4f,%.4f', $lat, $lon);
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }
        $places = $this->placeResolver->queryPoint($lat, $lon, $img->fileid ?? null);
        $pair = $this->pickPair($places);
        $this->cache[$key] = $pair;
        return $pair;
    }

    /**
     * Picks a city name (admin_level 8 / 7 / 6) and a suburb name (most
     * specific available at level >=8) from the OSM places result.
     *
     * OSM admin levels: 2=country, 4=state, 6=county / kreisfreie Stadt,
     * 7=metropolitan area, 8=city/town/Gemeinde, 9-12=districts/suburbs.
     *
     * Frankfurt am Main exists only at level 6 (kreisfreie Stadt), so we have
     * to look below 8 for the city fallback; Paris exists at level 8 with
     * arrondissements at level 9.
     *
     * @param array<int,array{osm_id:mixed,admin_level:mixed,name:mixed}> $places
     * @return array{city:?string,suburb:?string}
     */
    private function pickPair(array $places): array {
        if (empty($places)) {
            return ['city' => null, 'suburb' => null];
        }
        $byLevel = [];
        foreach ($places as $p) {
            $level = (int)($p['admin_level'] ?? 0);
            $name = isset($p['name']) ? trim((string)$p['name']) : '';
            if ($name === '' || isset($byLevel[$level])) {
                continue;
            }
            $byLevel[$level] = $name;
        }
        $city = null;
        foreach ([8, 7, 6] as $pref) {
            if (isset($byLevel[$pref])) {
                $city = $byLevel[$pref];
                break;
            }
        }
        $suburb = null;
        $maxLevel = -1;
        foreach ($byLevel as $level => $name) {
            if ($level >= 8 && $level > $maxLevel) {
                $maxLevel = $level;
                $suburb = $name;
            }
        }
        if ($city === null && $suburb === null) {
            // No level >=6 available — fall back to whatever's broadest.
            ksort($byLevel);
            $city = reset($byLevel) ?: null;
        }
        return ['city' => $city, 'suburb' => $suburb];
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
