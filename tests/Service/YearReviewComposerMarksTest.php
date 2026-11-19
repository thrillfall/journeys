<?php
namespace OCA\Journeys\Tests\Service;

use OCA\Journeys\Service\YearReviewComposer;
use PHPUnit\Framework\TestCase;

class YearReviewComposerMarksTest extends TestCase {
    private function item(string $basename, ?int $month, ?int $chapter, bool $closing = false): array {
        return ['basename' => $basename, 'month' => $month, 'chapter' => $chapter, 'closing' => $closing];
    }

    public function testHeadingsOnEveryNewMonthAndSlidesOnChapterChanges(): void {
        $marks = YearReviewComposer::chapterMarks([
            $this->item('yr-intro', null, null),
            $this->item('00001', 4, 0),
            $this->item('00002', 4, 0),
            $this->item('yr-map-00', 4, 1),
            $this->item('00003', 4, 1),
            $this->item('00004', 4, 0),
            $this->item('00005', 7, 0),
            $this->item('00006', 7, 0),
            $this->item('yr-stats', null, null, true),
        ]);

        $this->assertSame(['00001' => 4, '00005' => 7], $marks['headings']);
        $this->assertSame([
            'yr-map-00' => YearReviewComposer::CHAPTER_TRANSITION,
            '00004' => YearReviewComposer::CHAPTER_TRANSITION,
            '00005' => YearReviewComposer::CHAPTER_TRANSITION,
            'yr-stats' => YearReviewComposer::CLOSING_TRANSITION,
        ], $marks['transitions']);
    }

    public function testMonthTurningInsideAJourneyGetsAHeading(): void {
        $marks = YearReviewComposer::chapterMarks([
            $this->item('yr-map-00', 10, 3),
            $this->item('00001', 10, 3),
            $this->item('00002', 11, 3),
            $this->item('00003', 11, 3),
        ]);
        $this->assertSame(['yr-map-00' => 10, '00002' => 11], $marks['headings']);
        $this->assertSame(['00002' => YearReviewComposer::CHAPTER_TRANSITION], $marks['transitions']);
    }
}
