<?php
namespace OCA\Journeys\Model;

/**
 * One section of a year-in-review video: a journey away from home, or the
 * everyday photos of one month.
 */
class YearReviewChapter {
    public const KIND_TRIP = 'trip';
    public const KIND_EXCURSION = 'excursion';
    public const KIND_EVERYDAY = 'everyday';

    /**
     * @param Image[] $images candidate pool, chronological
     */
    public function __construct(
        public string $kind,
        public string $label,
        public array $images,
    ) {}

    public function isJourney(): bool {
        return $this->kind !== self::KIND_EVERYDAY;
    }

    /** Calendar days covered, counted on Memories' local wall-clock datetaken. */
    public function days(): int {
        if ($this->images === []) {
            return 0;
        }
        $first = substr($this->images[0]->datetaken, 0, 10);
        $last = substr($this->images[count($this->images) - 1]->datetaken, 0, 10);
        $a = strtotime($first . ' 00:00:00 UTC');
        $b = strtotime($last . ' 00:00:00 UTC');
        if ($a === false || $b === false) {
            return 1;
        }
        return max(1, intdiv(abs($b - $a), 86400) + 1);
    }
}
