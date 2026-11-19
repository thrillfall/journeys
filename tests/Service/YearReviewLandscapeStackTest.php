<?php
namespace OCA\Journeys\Tests\Service;

use OCA\Journeys\Model\Image;
use OCA\Journeys\Service\YearReviewImageProvider;
use PHPUnit\Framework\TestCase;

class YearReviewLandscapeStackTest extends TestCase {
    private function img(int $id, bool $landscape, ?bool $faces = null, bool $video = false): Image {
        return new Image($id, "p$id.jpg", sprintf('2024-05-01 10:%02d:00', $id), null, null, $landscape ? 4000 : 3000, $landscape ? 3000 : 4000, $faces, isVideo: $video);
    }

    private function ids(array $images): array {
        return array_map(fn(Image $i) => $i->fileid, $images);
    }

    public function testDropsSurplusLandscapesFacelessAndLatestFirst(): void {
        $images = [
            $this->img(1, true, true),
            $this->img(2, false),
            $this->img(3, true, false),
            $this->img(4, true, true),
            $this->img(5, true, false),
            $this->img(6, true, true),
        ];
        $this->assertSame([1, 2, 4, 6], $this->ids(YearReviewImageProvider::fitLandscapesToStacks($images)));
    }

    public function testFallsBackToLandscapesWithFaces(): void {
        $images = [$this->img(1, true, true), $this->img(2, true, true), $this->img(3, false)];
        $this->assertSame([3], $this->ids(YearReviewImageProvider::fitLandscapesToStacks($images)));
    }

    public function testKeepsCompleteStacksAndIgnoresClips(): void {
        $images = [$this->img(1, true), $this->img(2, true), $this->img(3, true), $this->img(4, true, null, true), $this->img(5, false)];
        $this->assertSame([1, 2, 3, 4, 5], $this->ids(YearReviewImageProvider::fitLandscapesToStacks($images)));
    }
}
