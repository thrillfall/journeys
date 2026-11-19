<?php
namespace OCA\Journeys\Tests\Service;

use OCA\Journeys\Model\Image;
use OCA\Journeys\Model\YearReviewChapter;
use OCA\Journeys\Service\YearReviewPlanner;
use PHPUnit\Framework\TestCase;

class YearReviewPlannerTest extends TestCase {
    private function chapter(string $kind, string $startDay, int $days, int $photos): YearReviewChapter {
        $images = [];
        $start = strtotime($startDay . ' 10:00:00 UTC');
        for ($i = 0; $i < $photos; $i++) {
            $offset = $photos > 1 ? (int)(($days - 1) * 86400 * $i / ($photos - 1)) : 0;
            $images[] = new Image($i + 1, "p$i.jpg", gmdate('Y-m-d H:i:s', $start + $offset), null, null, 3000, 4000);
        }
        return new YearReviewChapter($kind, $kind . $startDay, $images);
    }

    private function months(int $photosEach): array {
        $out = [];
        for ($m = 1; $m <= 12; $m++) {
            $out[] = $this->chapter(YearReviewChapter::KIND_EVERYDAY, sprintf('2026-%02d-01', $m), 28, $photosEach);
        }
        return $out;
    }

    public function testBudgetIsSpentAndJourneysGetTheirShare(): void {
        $chapters = array_merge(
            [$this->chapter(YearReviewChapter::KIND_TRIP, '2026-06-01', 21, 900)],
            [$this->chapter(YearReviewChapter::KIND_TRIP, '2026-09-10', 3, 200)],
            $this->months(300),
        );
        $quota = (new YearReviewPlanner())->allocate($chapters, 72);

        $this->assertSame(72, array_sum($quota));
        $this->assertSame((int)round(72 * YearReviewPlanner::JOURNEY_SHARE), $quota[0] + $quota[1]);
        $this->assertGreaterThan($quota[1], $quota[0]);
        foreach (array_slice($quota, 2) as $q) {
            $this->assertGreaterThanOrEqual(1, $q);
        }
    }

    public function testEverydayMonthsAreSpreadEvenly(): void {
        $quota = (new YearReviewPlanner())->allocate($this->months(100), 30);
        $this->assertSame(30, array_sum($quota));
        $this->assertLessThanOrEqual(1, max($quota) - min($quota));
    }

    public function testShortTripIsNotDrownedOutByALongOne(): void {
        $chapters = [
            $this->chapter(YearReviewChapter::KIND_TRIP, '2026-02-01', 2, 100),
            $this->chapter(YearReviewChapter::KIND_TRIP, '2026-07-01', 28, 3000),
        ];
        $quota = (new YearReviewPlanner())->allocate($chapters, 60);
        $this->assertSame(60, array_sum($quota));
        $this->assertGreaterThanOrEqual(15, $quota[0]);
    }

    public function testUnusedJourneyShareGoesToEverydayAndBack(): void {
        $chapters = array_merge([$this->chapter(YearReviewChapter::KIND_EXCURSION, '2026-05-01', 1, 4)], $this->months(100));
        $quota = (new YearReviewPlanner())->allocate($chapters, 72);
        $this->assertSame(4, $quota[0]);
        $this->assertSame(72, array_sum($quota));

        $sparse = array_merge([$this->chapter(YearReviewChapter::KIND_TRIP, '2026-05-01', 5, 500)], [$this->chapter(YearReviewChapter::KIND_EVERYDAY, '2026-01-01', 10, 3)]);
        $quota = (new YearReviewPlanner())->allocate($sparse, 72);
        $this->assertSame([69, 3], $quota);
    }

    public function testLightestChaptersAreDroppedWhenFloorsExceedTheBudget(): void {
        $chapters = [];
        for ($i = 0; $i < 10; $i++) {
            $chapters[] = $this->chapter(YearReviewChapter::KIND_EXCURSION, sprintf('2026-%02d-01', $i + 1), 1, 10);
        }
        $chapters[] = $this->chapter(YearReviewChapter::KIND_TRIP, '2026-12-01', 10, 100);
        $quota = (new YearReviewPlanner())->allocate($chapters, 10);

        $this->assertSame(10, array_sum($quota));
        $this->assertGreaterThanOrEqual(3, $quota[10]);
        foreach ($quota as $q) {
            $this->assertTrue($q === 0 || $q >= 2);
        }
    }

    public function testFewerPhotosThanBudgetUsesThemAll(): void {
        $chapters = [$this->chapter(YearReviewChapter::KIND_EVERYDAY, '2026-03-01', 5, 7)];
        $this->assertSame([7], (new YearReviewPlanner())->allocate($chapters, 72));
    }
}
