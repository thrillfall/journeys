<?php
namespace OCA\Journeys\Service;

use OCA\Journeys\Model\Image;

/**
 * Resolves the denormalized location cache for a diary entry from its selected
 * photos: a representative coordinate (the medoid of the geolocated photos) plus
 * place label / city / country, reusing the same Memories-backed resolvers the
 * clustering pipeline uses.
 *
 * The representative point is a *medoid*, not the arithmetic mean: the mean of
 * photos that span both shores of a bay (or opposite ends of an island) can
 * land in open water, which then shows up as a map marker "in the sea". A
 * medoid is always one of the real photo coordinates, so it sits on land where
 * the photos were actually taken.
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

        $point = self::medoid(array_map(
            static fn(Image $i) => [(float)$i->lat, (float)$i->lon],
            $geo
        ));

        $result = $empty;
        if ($point !== null) {
            $result['lat'] = $point[0];
            $result['lon'] = $point[1];
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
     * Medoid of a set of [lat, lon] points: the input point with the smallest
     * total distance to all the others. Always one of the input points (so it
     * is a real photo location, never open water), and biased toward the
     * densest cluster — i.e. where most of the day's photos were taken. Returns
     * null for an empty set. Pure — unit tested.
     *
     * Distance is planar in degrees with longitude scaled by cos(lat) so the
     * east-west axis isn't overweighted at non-equatorial latitudes; this is
     * for ranking only, so the small-area approximation is fine.
     *
     * @param array<int,array{0:float,1:float}> $points
     * @return array{0:float,1:float}|null
     */
    public static function medoid(array $points): ?array {
        if (!$points) {
            return null;
        }
        $cosLat = cos(deg2rad(self::centroid($points)[0]));
        $best = null;
        $bestTotal = INF;
        foreach ($points as $candidate) {
            $total = 0.0;
            foreach ($points as $other) {
                $dLat = $candidate[0] - $other[0];
                $dLon = ($candidate[1] - $other[1]) * $cosLat;
                $total += sqrt($dLat * $dLat + $dLon * $dLon);
            }
            if ($total < $bestTotal) {
                $bestTotal = $total;
                $best = $candidate;
            }
        }
        return $best;
    }

    /**
     * Arithmetic mean of a set of [lat, lon] points. Returns null for an empty
     * set. Pure — unit tested. Used as the reference center for medoid ranking.
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
