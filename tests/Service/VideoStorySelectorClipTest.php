<?php
namespace OCA\Journeys\Tests\Service;

use OCA\Journeys\Model\Image;
use OCA\Journeys\Service\VideoStorySelector;
use PHPUnit\Framework\TestCase;

class VideoStorySelectorClipTest extends TestCase {
    private function photo(int $id, int $minute): Image {
        return new Image($id, "files/p$id.jpg", date('Y-m-d H:i:s', 1_700_000_000 + $minute * 60), null, null, 3000, 4000);
    }

    private function video(int $id, int $minute, int $seconds): Image {
        return new Image($id, "files/v$id.mp4", date('Y-m-d H:i:s', 1_700_000_000 + $minute * 60), null, null, 1080, 1920, isVideo: true, videoDuration: $seconds);
    }

    public function testVideosTakeAtMostTheClipBudgetAndShrinkThePhotoShare(): void {
        $items = [];
        for ($i = 0; $i < 200; $i++) {
            $items[] = $this->photo($i, $i * 10);
        }
        for ($i = 0; $i < 40; $i++) {
            $items[] = $this->video(1000 + $i, $i * 50 + 3, 8);
        }

        $selected = (new VideoStorySelector())->selectImages('u', $items, 5, 80, false, 'portrait', true);

        $clips = array_filter($selected, fn(Image $img) => $img->isVideo);
        $this->assertCount(VideoStorySelector::clipBudget(80), $clips);
        $this->assertCount(80, $selected);
    }

    public function testSelectionStaysChronological(): void {
        $items = [$this->video(1, 5, 6), $this->photo(2, 0), $this->photo(3, 10)];
        $selected = (new VideoStorySelector())->selectImages('u', $items, 5, 80, false, 'portrait', true);
        $this->assertSame([2, 1, 3], array_map(fn(Image $img) => $img->fileid, $selected));
    }

    public function testVeryShortAndDisabledVideosAreDropped(): void {
        $items = [$this->photo(1, 0), $this->video(2, 5, 1), $this->video(3, 10, 6)];
        $selector = new VideoStorySelector();

        $this->assertSame([1, 3], array_map(fn(Image $img) => $img->fileid, $selector->selectImages('u', $items, 5, 80, false, 'portrait')));
        $this->assertSame([1], array_map(fn(Image $img) => $img->fileid, $selector->selectImages('u', $items, 5, 80, false, 'portrait', false)));
    }
}
