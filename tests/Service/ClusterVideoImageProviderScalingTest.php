<?php
namespace OCA\Journeys\Tests\Service;

use DateTimeImmutable;
use OCA\Journeys\Service\ClusterVideoImageProvider;
use PHPUnit\Framework\TestCase;

class ClusterVideoImageProviderScalingTest extends TestCase {
    public function testWeekendStaysAtBaseline(): void {
        $start = new DateTimeImmutable('2026-04-01 09:00:00');
        $end = new DateTimeImmutable('2026-04-03 18:00:00');
        $this->assertSame(80, ClusterVideoImageProvider::scaleMaxImagesByDaySpan(120, $start, $end));
    }

    public function testSevenDayTripStaysAtBaseline(): void {
        $start = new DateTimeImmutable('2026-04-01 09:00:00');
        $end = new DateTimeImmutable('2026-04-07 22:00:00');
        $this->assertSame(80, ClusterVideoImageProvider::scaleMaxImagesByDaySpan(120, $start, $end));
    }

    public function testTwoWeekTripScalesUp(): void {
        $start = new DateTimeImmutable('2026-04-01 09:00:00');
        $end = new DateTimeImmutable('2026-04-14 18:00:00');
        // 14-day span → 80 + (14 - 7) * 4 = 108
        $this->assertSame(108, ClusterVideoImageProvider::scaleMaxImagesByDaySpan(120, $start, $end));
    }

    public function testThreeWeekTripCapsAtAbsoluteMax(): void {
        $start = new DateTimeImmutable('2026-04-01 09:00:00');
        $end = new DateTimeImmutable('2026-04-21 18:00:00');
        $this->assertSame(120, ClusterVideoImageProvider::scaleMaxImagesByDaySpan(120, $start, $end));
    }

    public function testExplicitLowerCapIsRespected(): void {
        $start = new DateTimeImmutable('2026-04-01 09:00:00');
        $end = new DateTimeImmutable('2026-04-21 18:00:00');
        // Long trip, but caller explicitly capped to 60 → must not exceed.
        $this->assertSame(60, ClusterVideoImageProvider::scaleMaxImagesByDaySpan(60, $start, $end));
    }

    public function testSingleDaySpanIsAtLeastOne(): void {
        $start = new DateTimeImmutable('2026-04-01 09:00:00');
        $end = new DateTimeImmutable('2026-04-01 10:00:00');
        $this->assertSame(80, ClusterVideoImageProvider::scaleMaxImagesByDaySpan(120, $start, $end));
    }
}
