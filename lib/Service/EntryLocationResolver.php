<?php
namespace OCA\Journeys\Service;

use OCA\Journeys\Model\Image;

/**
 * Resolves the denormalized location cache for a diary entry from its selected
 * photos: a representative coordinate (centroid of the geolocated photos) plus
 * place label / city / country, reusing the same Memories-backed resolvers the
 * clustering pipeline uses.
 *
 * Resolution is best-effort: if the Memories Places tables are not populated (no
 * reverse-geocoding), the coordinate is still stored and the place fields stay
 * null. country_code (ISO) is not yet derived — no ISO source is available from
 * the OSM planet data the resolvers read; deferred to a later increment.
 */
class EntryLocationResolver {

    public function __construct(
        private ImageFetcher $imageFetcher,
        private ClusterLocationResolver $locationResolver,
    ) {}

    /**
     * @param int[] $fileIds the entry's curated photos
     * @return array{lat:?float,lon:?float,placeLabel:?string,city:?string,country:?string,countryCode:?string}
     */
    public function resolveForFileIds(string $user, array $fileIds): array {
        $empty = [
            'lat' => null, 'lon' => null, 'placeLabel' => null,
            'city' => null, 'country' => null, 'countryCode' => null,
        ];
        $fileIds = array_values(array_unique(array_filter(array_map('intval', $fileIds), static fn($v) => $v > 0)));
        if (!$fileIds) {
            return $empty;
        }

        $images = $this->imageFetcher->fetchImagesByFileIds($user, $fileIds);
        $geo = array_values(array_filter($images, static fn(Image $i) => $i->lat !== null && $i->lon !== null));
        if (!$geo) {
            return $empty;
        }

        $centroid = self::centroid(array_map(
            static fn(Image $i) => [(float)$i->lat, (float)$i->lon],
            $geo
        ));

        $result = $empty;
        if ($centroid !== null) {
            $result['lat'] = $centroid[0];
            $result['lon'] = $centroid[1];
        }
        // Best-effort place names; never let a missing/broken Places DB block a save.
        try {
            $result['placeLabel'] = $this->locationResolver->resolveClusterLocation($geo, true) ?: null;
        } catch (\Throwable $e) {}
        try {
            $result['city'] = $this->locationResolver->resolveClusterLocation($geo, false) ?: null;
        } catch (\Throwable $e) {}
        try {
            $result['country'] = $this->locationResolver->resolveClusterCountry($geo) ?: null;
        } catch (\Throwable $e) {}
        return $result;
    }

    /**
     * Average of a set of [lat, lon] points. Returns null for an empty set.
     * Pure — unit tested.
     *
     * @param array<int,array{0:float,1:float}> $points
     * @return array{0:float,1:float}|null
     */
    public static function centroid(array $points): ?array {
        if (!$points) {
            return null;
        }
        $sumLat = 0.0;
        $sumLon = 0.0;
        foreach ($points as $p) {
            $sumLat += $p[0];
            $sumLon += $p[1];
        }
        $n = count($points);
        return [$sumLat / $n, $sumLon / $n];
    }
}
