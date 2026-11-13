<?php
namespace OCA\Journeys\Service;

/**
 * A journey counts as a trip when it left the home radius and spans at least
 * one night. Everything else is an excursion (away, same day) or nearby.
 */
class TripClassifier {
    public const KIND_TRIP = 'trip';
    public const KIND_EXCURSION = 'excursion';
    public const KIND_NEARBY = 'nearby';

    /**
     * @param iterable<array{0:?float,1:?float}> $points lat/lon pairs
     * @param array{lat:float,lon:float,radiusKm:float}|null $home
     * @return bool|null null when home or every coordinate is unknown
     */
    public function isAway(iterable $points, ?array $home): ?bool {
        if ($home === null || !isset($home['lat'], $home['lon'])) {
            return null;
        }
        $radius = (float)($home['radiusKm'] ?? 50.0);
        $sawCoordinate = false;
        foreach ($points as [$lat, $lon]) {
            if ($lat === null || $lon === null) {
                continue;
            }
            $sawCoordinate = true;
            if ($this->haversineKm((float)$lat, (float)$lon, (float)$home['lat'], (float)$home['lon']) > $radius) {
                return true;
            }
        }
        return $sawCoordinate ? false : null;
    }

    /**
     * Start and end are Memories' datetaken values: local wall-clock time
     * without a zone, so comparing the date part is the calendar day the
     * photos were taken on.
     */
    public function spansNight(?string $start, ?string $end): bool {
        if ($start === null || $end === null || $start === '' || $end === '') {
            return false;
        }
        return substr(trim($start), 0, 10) !== substr(trim($end), 0, 10);
    }

    /**
     * With an unknown home the night alone decides, so a user without a home
     * still sees their multi-day journeys as trips.
     */
    public function kind(?bool $isAway, ?string $start, ?string $end): string {
        if ($isAway === false) {
            return self::KIND_NEARBY;
        }
        return $this->spansNight($start, $end) ? self::KIND_TRIP : self::KIND_EXCURSION;
    }

    public function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return 6371.0 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
