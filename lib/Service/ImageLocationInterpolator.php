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
    public static function interpolate(array $images, int $maxGapSeconds = 21600): array {
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
                if (($t - $tPrev) <= $maxGapSeconds && ($tNext - $t) <= $maxGapSeconds) {
                    // Interpolate
                    $frac = ($t - $tPrev) / ($tNext - $tPrev);
                    $lat = (float)$images[$prev]->lat + $frac * ((float)$images[$next]->lat - (float)$images[$prev]->lat);
                    $lon = (float)$images[$prev]->lon + $frac * ((float)$images[$next]->lon - (float)$images[$prev]->lon);
                    $result[$i] = new Image($images[$i]->fileid, $images[$i]->path, $images[$i]->datetaken, (string)$lat, (string)$lon);
                }
            } elseif ($prev !== null) {
                $tPrev = strtotime($images[$prev]->datetaken);
                if (($t - $tPrev) <= $maxGapSeconds) {
                    $lat = $images[$prev]->lat;
                    $lon = $images[$prev]->lon;
                    $result[$i] = new Image($images[$i]->fileid, $images[$i]->path, $images[$i]->datetaken, $lat, $lon);
                }
            } elseif ($next !== null) {
                $tNext = strtotime($images[$next]->datetaken);
                if (($tNext - $t) <= $maxGapSeconds) {
                    $lat = $images[$next]->lat;
                    $lon = $images[$next]->lon;
                    $result[$i] = new Image($images[$i]->fileid, $images[$i]->path, $images[$i]->datetaken, $lat, $lon);
                }
            }
            // else: leave as is
        }
        return $result;
    }
}
