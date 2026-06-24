<?php
namespace OCA\Journeys\Tests\Model;

use OCA\Journeys\Model\EntryPhoto;
use PHPUnit\Framework\TestCase;

class EntryPhotoTest extends TestCase {

    public function testNormalizeBareFileidsAssignsDenseOrder(): void {
        $out = EntryPhoto::normalizeSelection([30, 10, 20]);
        $this->assertSame([
            ['fileid' => 30, 'caption' => null, 'sort_order' => 0],
            ['fileid' => 10, 'caption' => null, 'sort_order' => 1],
            ['fileid' => 20, 'caption' => null, 'sort_order' => 2],
        ], $out);
    }

    public function testNormalizeDropsDuplicatesKeepingFirstOccurrence(): void {
        $out = EntryPhoto::normalizeSelection([5, 9, 5, 9, 7]);
        $this->assertSame([5, 9, 7], array_column($out, 'fileid'));
        $this->assertSame([0, 1, 2], array_column($out, 'sort_order'));
    }

    public function testNormalizeDropsNonPositiveFileids(): void {
        $out = EntryPhoto::normalizeSelection([0, -3, 8, 'x', 4]);
        $this->assertSame([8, 4], array_column($out, 'fileid'));
    }

    public function testNormalizeAcceptsMapsWithCaptions(): void {
        $out = EntryPhoto::normalizeSelection([
            ['fileid' => 12, 'caption' => '  sunset  '],
            ['fileid' => 13, 'caption' => '   '],
            ['fileid' => 14],
        ]);
        $this->assertSame('sunset', $out[0]['caption']);
        $this->assertNull($out[1]['caption']);
        $this->assertNull($out[2]['caption']);
        $this->assertSame([12, 13, 14], array_column($out, 'fileid'));
    }

    public function testNormalizeEmpty(): void {
        $this->assertSame([], EntryPhoto::normalizeSelection([]));
    }
}
