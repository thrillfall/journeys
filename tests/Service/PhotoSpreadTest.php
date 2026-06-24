<?php
namespace OCA\Journeys\Tests\Service;

use OCA\Journeys\Service\PhotoSpread;
use PHPUnit\Framework\TestCase;

class PhotoSpreadTest extends TestCase {

    private function photo(int $id, string $dt): array {
        return ['fileid' => $id, 'datetaken' => $dt];
    }

    public function testFewerThanMaxReturnsAll(): void {
        $in = [$this->photo(1, '2026-06-03 09:00:00'), $this->photo(2, '2026-06-03 10:00:00')];
        $this->assertSame($in, PhotoSpread::pick($in, 20));
    }

    public function testEmptyReturnsEmpty(): void {
        $this->assertSame([], PhotoSpread::pick([], 20));
    }

    public function testCapsAtMaxAndKeepsEndpoints(): void {
        $in = [];
        for ($i = 0; $i < 100; $i++) {
            $in[] = $this->photo($i, sprintf('2026-06-03 %02d:%02d:00', 8 + intdiv($i, 6), ($i % 6) * 10));
        }
        $out = PhotoSpread::pick($in, 20);
        $this->assertCount(20, $out);
        // first and last of the day are preserved
        $this->assertSame(0, $out[0]['fileid']);
        $this->assertSame(99, $out[19]['fileid']);
        // distinct + time-ordered
        $ids = array_column($out, 'fileid');
        $this->assertSame($ids, array_values(array_unique($ids)));
        $sorted = $ids; sort($sorted);
        $this->assertSame($sorted, $ids);
    }

    public function testNoBurstDuplicatesAcrossTemporalGaps(): void {
        // Real-world shape (cf. 2024-11-03): a dense afternoon, a long evening
        // void, then a same-second burst at the end. The old "nearest to evenly
        // spaced times" picker piled several identical-timestamp frames around
        // the void; farthest-point sampling must not.
        $in = [];
        for ($i = 0; $i < 40; $i++) { // afternoon, ~1.5 min apart
            $in[] = $this->photo($i, sprintf('2024-11-03 15:%02d:%02d', intdiv($i * 90, 60) % 60 + intdiv($i, 40), ($i * 90) % 60));
        }
        for ($i = 0; $i < 10; $i++) { // evening burst, all the SAME second
            $in[] = $this->photo(900 + $i, '2024-11-03 22:35:36');
        }
        $out = PhotoSpread::pick($in, 20);
        $this->assertCount(20, $out);
        $times = array_column($out, 'datetaken');
        // No duplicate timestamps → at most one frame from the same-second burst.
        $this->assertSame(count($times), count(array_unique($times)), 'picked duplicate-timestamp burst frames');
        $burstPicks = count(array_filter($times, static fn($t) => $t === '2024-11-03 22:35:36'));
        $this->assertSame(1, $burstPicks, 'evening burst should contribute exactly one pick');
    }

    public function testSpreadByTimeNotByCount(): void {
        // 60 photos in a 10-minute morning burst + 1 lone evening photo.
        $in = [];
        for ($i = 0; $i < 60; $i++) {
            $in[] = $this->photo($i, sprintf('2026-06-03 09:0%d:%02d', intdiv($i, 6), ($i % 6) * 10));
        }
        $in[] = $this->photo(999, '2026-06-03 21:00:00');
        $out = PhotoSpread::pick($in, 20);
        $ids = array_column($out, 'fileid');
        // The evening outlier must be included — proves time-based, not index-based, spread.
        $this->assertContains(999, $ids);
        $this->assertCount(20, $out);
    }
}
