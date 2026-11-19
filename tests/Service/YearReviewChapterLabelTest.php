<?php
namespace OCA\Journeys\Tests\Service;

use OCA\Journeys\Service\YearReviewImageProvider;
use PHPUnit\Framework\TestCase;

class YearReviewChapterLabelTest extends TestCase {
    public function testCustomNameWins(): void {
        $this->assertSame('Sommer am Meer', YearReviewImageProvider::chapterLabel([
            'custom_name' => ' Sommer am Meer ', 'location' => 'València — Poblats Marítims', 'name' => 'x',
        ]));
    }

    public function testAutoNameShowsThePlaceWithoutDistrict(): void {
        $this->assertSame('València', YearReviewImageProvider::chapterLabel([
            'custom_name' => null, 'location' => 'València — Poblats Marítims', 'name' => 'València — Poblats Marítims July 2024 (Jul 21–Jul 31)',
        ]));
        $this->assertSame('New Zealand', YearReviewImageProvider::chapterLabel([
            'custom_name' => null, 'location' => 'New Zealand/Aotearoa', 'name' => 'n',
        ]));
    }

    public function testFallsBackToTheNameWithoutLocation(): void {
        $this->assertSame('Cluster 03 2024-05-01', YearReviewImageProvider::chapterLabel([
            'custom_name' => null, 'location' => null, 'name' => 'Cluster 03 2024-05-01',
        ]));
    }
}
