<?php
namespace OCA\Journeys\Model;

class YearReviewStats {
    /**
     * @param string[] $countries visited on journeys away from home
     */
    public function __construct(
        public int $trips = 0,
        public int $excursions = 0,
        public int $daysAway = 0,
        public array $countries = [],
        public ?int $farthestKm = null,
        public int $photos = 0,
    ) {}
}
