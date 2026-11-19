<?php
namespace OCA\Journeys\Service;

/**
 * When the automatic year-in-review is due: from three days before New
 * Year's Eve to the end of the year, once per user and year. Starting on the
 * 28th rather than the 31st leaves room for a missed cron run and has the
 * film ready before the year ends.
 */
class YearReviewSchedule {
    public const FIRST_DAY = '12-28';

    /**
     * @return int|null the year to render, or null when nothing is due
     */
    public static function dueYear(\DateTimeInterface $today, ?int $lastQueuedYear): ?int {
        $year = (int)$today->format('Y');
        if ($today->format('m-d') < self::FIRST_DAY || $lastQueuedYear === $year) {
            return null;
        }
        return $year;
    }
}
