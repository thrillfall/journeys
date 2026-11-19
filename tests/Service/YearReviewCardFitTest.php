<?php
namespace OCA\Journeys\Tests\Service;

use OCA\Journeys\Service\YearReviewCardRenderer;
use PHPUnit\Framework\TestCase;

class YearReviewCardFitTest extends TestCase {
    public function testShortTextKeepsItsSize(): void {
        $this->assertSame([['Köln'], 81], YearReviewCardRenderer::fitText('Köln', 81, 950, 2));
    }

    public function testLongSingleLineShrinksToFit(): void {
        [$lines, $size] = YearReviewCardRenderer::fitText('18,135 km from home at the farthest', 66, 950, 1);
        $this->assertCount(1, $lines);
        $this->assertLessThan(66, $size);
        $this->assertLessThanOrEqual(950, mb_strlen($lines[0]) * 0.66 * $size);
    }

    public function testWrapsIntoCentredLinesAndSpacesSlashes(): void {
        [$lines, $size] = YearReviewCardRenderer::fitText('New Zealand/Aotearoa', 81, 950, 2);
        $this->assertSame(['New Zealand /', 'Aotearoa'], $lines);
        $this->assertSame(81, $size);
    }
}
