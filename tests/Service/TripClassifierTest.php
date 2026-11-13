<?php
namespace OCA\Journeys\Tests\Service;

use OCA\Journeys\Service\TripClassifier;
use PHPUnit\Framework\TestCase;

class TripClassifierTest extends TestCase {
    private const BERLIN = ['lat' => 52.52, 'lon' => 13.405, 'radiusKm' => 50.0];

    private TripClassifier $classifier;

    protected function setUp(): void {
        $this->classifier = new TripClassifier();
    }

    public function testAwayWhenAnyPhotoLeavesTheRadius(): void {
        $points = [[52.52, 13.40], [52.40, 13.06], [51.34, 12.37]];
        $this->assertTrue($this->classifier->isAway($points, self::BERLIN));
    }

    public function testNotAwayWhenAllPhotosStayInsideTheRadius(): void {
        $points = [[52.52, 13.40], [52.40, 13.06], [null, null]];
        $this->assertFalse($this->classifier->isAway($points, self::BERLIN));
    }

    public function testUnknownWithoutHome(): void {
        $this->assertNull($this->classifier->isAway([[48.1, 11.5]], null));
    }

    public function testUnknownWithoutAnyCoordinate(): void {
        $this->assertNull($this->classifier->isAway([[null, null]], self::BERLIN));
    }

    public function testSaturdayToSundayIsANight(): void {
        $this->assertTrue($this->classifier->spansNight('2026-05-02 10:00:00', '2026-05-03 18:00:00'));
    }

    public function testLongSingleDayIsNoNight(): void {
        $this->assertFalse($this->classifier->spansNight('2026-05-02 06:00:00', '2026-05-02 23:59:00'));
    }

    public function testMissingDatesAreNoNight(): void {
        $this->assertFalse($this->classifier->spansNight(null, '2026-05-02 06:00:00'));
    }

    public function testAwayOvernightIsATrip(): void {
        $this->assertSame(TripClassifier::KIND_TRIP, $this->classifier->kind(true, '2026-05-02 10:00:00', '2026-05-03 18:00:00'));
    }

    public function testAwaySameDayIsAnExcursion(): void {
        $this->assertSame(TripClassifier::KIND_EXCURSION, $this->classifier->kind(true, '2026-05-02 10:00:00', '2026-05-02 18:00:00'));
    }

    public function testNearHomeIsNearbyEvenOverSeveralDays(): void {
        $this->assertSame(TripClassifier::KIND_NEARBY, $this->classifier->kind(false, '2026-05-01 10:00:00', '2026-05-04 18:00:00'));
    }

    public function testUnknownHomeFallsBackToTheNight(): void {
        $this->assertSame(TripClassifier::KIND_TRIP, $this->classifier->kind(null, '2026-05-01 10:00:00', '2026-05-02 18:00:00'));
        $this->assertSame(TripClassifier::KIND_EXCURSION, $this->classifier->kind(null, '2026-05-01 10:00:00', '2026-05-01 18:00:00'));
    }
}
