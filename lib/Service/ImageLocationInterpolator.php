<?php
namespace OCA\Journeys\Service;

use OCA\Journeys\Model\Image;

class ImageLocationInterpolator {
    /**
     * Interpolate missing locations for images based on nearest images with location.
     * Only interpolate if both neighbors are within $maxGapSeconds of the image.
     *
     * @param Image[] $images Sorted by datetaken ascending
     * @param int $maxGapSeconds
     * @return Image[] New array of images with interpolated locations where possible
     */
    public static function interpolate(array $images, int $maxGapSeconds = 21600, float $maxDistanceKm = 1.0): array {
        $n = count($images);
        if ($n === 0) return $images;
        $result = $images;
        // Build list of indices with location
        $withLoc = [];
        for ($i = 0; $i < $n; $i++) {
            if ($images[$i]->lat !== null && $images[$i]->lon !== null) {
                $withLoc[] = $i;
            }
        }
        for ($i = 0; $i < $n; $i++) {
            if ($images[$i]->lat !== null && $images[$i]->lon !== null) continue;
            // Find previous and next with location
            $prev = null; $next = null;
            foreach ($withLoc as $idx) {
                if ($idx < $i) $prev = $idx;
                if ($idx > $i) { $next = $idx; break; }
            }
            $t = strtotime($images[$i]->datetaken);
            if ($prev !== null && $next !== null) {
                $tPrev = strtotime($images[$prev]->datetaken);
                $tNext = strtotime($images[$next]->datetaken);
                // Guard invalid timestamps
                if ($tPrev === false || $tNext === false) {
                    // cannot interpolate without valid neighbor timestamps
                    // fall through to single-reference cases below
                } else if (($t - $tPrev) <= $maxGapSeconds && ($tNext - $t) <= $maxGapSeconds) {
                    // Check spatial constraint (distance between prev and next <= 1km)
                    $distance = self::haversineDistance((float)$images[$prev]->lat, (float)$images[$prev]->lon, (float)$images[$next]->lat, (float)$images[$next]->lon);
                    if ($distance > $maxDistanceKm) {
                        continue; // skip interpolation if too far
                    }
                    // Interpolate safely; if timestamps are equal, use midpoint to avoid division by zero
                    $span = $tNext - $tPrev;
                    if ($span === 0) {
                        $lat = ((float)$images[$prev]->lat + (float)$images[$next]->lat) / 2.0;
                        $lon = ((float)$images[$prev]->lon + (float)$images[$next]->lon) / 2.0;
                    } else if ($span < 0) {
                        // out-of-order timestamps; skip interpolation
                        $lat = null; $lon = null;
                    } else {
                        $frac = ($t - $tPrev) / $span;
                        $lat = (float)$images[$prev]->lat + $frac * ((float)$images[$next]->lat - (float)$images[$prev]->lat);
                        $lon = (float)$images[$prev]->lon + $frac * ((float)$images[$next]->lon - (float)$images[$prev]->lon);
                    }
                    if ($lat !== null && $lon !== null) {
                        $result[$i] = new Image($images[$i]->fileid, $images[$i]->path, $images[$i]->datetaken, (string)$lat, (string)$lon);
                    }
                }
            } elseif ($prev !== null) {
                $tPrev = strtotime($images[$prev]->datetaken);
                // Only allow single reference assignment if within 1 hour (3600s)
                if (($t - $tPrev) <= 3600) {
                    $lat = $images[$prev]->lat;
                    $lon = $images[$prev]->lon;
                    $result[$i] = new Image($images[$i]->fileid, $images[$i]->path, $images[$i]->datetaken, $lat, $lon);
                }
            } elseif ($next !== null) {
                $tNext = strtotime($images[$next]->datetaken);
                // Only allow single reference assignment if within 1 hour (3600s)
                if (($tNext - $t) <= 3600) {
                    $lat = $images[$next]->lat;
                    $lon = $images[$next]->lon;
                    $result[$i] = new Image($images[$i]->fileid, $images[$i]->path, $images[$i]->datetaken, $lat, $lon);
                }
            }
            // else: leave as is
        }
        return $result;
    }

    /**
     * Calculate the great-circle distance between two points using the Haversine formula.
     * @param float $lat1
     * @param float $lon1
     * @param float $lat2
     * @param float $lon2
     * @return float Distance in kilometers
     */
    private static function haversineDistance(float $lat1, float $lon1, float $lat2, float $lon2): float {
        $earthRadius = 6371.0; // km
        $lat1Rad = deg2rad($lat1);
        $lon1Rad = deg2rad($lon1);
        $lat2Rad = deg2rad($lat2);
        $lon2Rad = deg2rad($lon2);
        $dLat = $lat2Rad - $lat1Rad;
        $dLon = $lon2Rad - $lon1Rad;
        $a = sin($dLat/2) * sin($dLat/2) + cos($lat1Rad) * cos($lat2Rad) * sin($dLon/2) * sin($dLon/2);
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        return $earthRadius * $c;
    }
}
