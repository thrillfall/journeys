<?php
namespace OCA\Journeys\Tests\Service;

use OCA\Journeys\Service\YearReviewSchedule;
use PHPUnit\Framework\TestCase;

class YearReviewScheduleTest extends TestCase {
    private function on(string $day): \DateTimeImmutable {
        return new \DateTimeImmutable($day . ' 03:00:00');
    }

    public function testNotDueBeforeTheTwentyEighth(): void {
        $this->assertNull(YearReviewSchedule::dueYear($this->on('2026-12-27'), null));
        $this->assertNull(YearReviewSchedule::dueYear($this->on('2026-06-30'), null));
        $this->assertNull(YearReviewSchedule::dueYear($this->on('2027-01-02'), 2025));
    }

    public function testDueFromTheTwentyEighthToTheEndOfTheYear(): void {
        $this->assertSame(2026, YearReviewSchedule::dueYear($this->on('2026-12-28'), null));
        $this->assertSame(2026, YearReviewSchedule::dueYear($this->on('2026-12-31'), 2025));
    }

    public function testOncePerYear(): void {
        $this->assertNull(YearReviewSchedule::dueYear($this->on('2026-12-29'), 2026));
    }
}
