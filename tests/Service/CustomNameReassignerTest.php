<?php
namespace OCA\Journeys\Tests\Service;

use OCA\Journeys\Service\AlbumCreator;
use OCA\Journeys\Service\CustomNameReassigner;
use PHPUnit\Framework\TestCase;

class CustomNameReassignerTest extends TestCase {

    /**
     * Build a stubbed AlbumCreator that returns the supplied tracked clusters and per-album
     * file IDs, and records every setCustomName + renamePhotosAlbum call so tests can assert
     * which name landed on which album.
     *
     * @param array<int,array{album_id:int,name:string}> $tracked
     * @param array<int,int[]> $filesByAlbum
     */
    private function makeAlbumCreator(array $tracked, array $filesByAlbum, array &$nameUpdates, array &$renameUpdates): AlbumCreator {
        $creator = $this->createMock(AlbumCreator::class);
        $creator->method('getTrackedClusters')->willReturn($tracked);
        $creator->method('getAlbumFileIdsForUser')->willReturnCallback(
            static fn(string $u, int $aid): array => $filesByAlbum[$aid] ?? []
        );
        $creator->method('setCustomName')->willReturnCallback(
            static function (string $u, int $aid, ?string $name) use (&$nameUpdates): bool {
                $nameUpdates[$aid] = $name;
                return true;
            }
        );
        $creator->method('renamePhotosAlbum')->willReturnCallback(
            static function (string $u, int $aid, string $title) use (&$renameUpdates): bool {
                $renameUpdates[$aid] = $title;
                return true;
            }
        );
        return $creator;
    }

    private function reassigner(AlbumCreator $creator): CustomNameReassigner {
        return new CustomNameReassigner($creator);
    }

    public function testExactMatchInheritsName(): void {
        $tracked = [['album_id' => 100, 'name' => 'Berlin Dec 2024']];
        $files = [100 => [1, 2, 3, 4]];
        $nameUpdates = [];
        $renameUpdates = [];
        $creator = $this->makeAlbumCreator($tracked, $files, $nameUpdates, $renameUpdates);

        $snapshot = [
            ['album_id' => 999, 'custom_name' => 'Christmas 2024', 'file_ids' => [1, 2, 3, 4]],
        ];
        $report = $this->reassigner($creator)->reassign('alice', $snapshot);

        $this->assertCount(1, $report['matched']);
        $this->assertSame('Christmas 2024', $report['matched'][0]['old_name']);
        $this->assertSame(100, $report['matched'][0]['album_id']);
        $this->assertEqualsWithDelta(1.0, $report['matched'][0]['jaccard'], 1e-9);
        $this->assertSame('Christmas 2024', $nameUpdates[100]);
        $this->assertSame('Christmas 2024', $renameUpdates[100]);
        $this->assertSame([], $report['dropped']);
    }

    public function testShiftedBoundaryStillMatches(): void {
        // Old: [1,2,3,4]; new: [1,2,3,4,5,6] (added photos). Jaccard = 4/6 ≈ 0.667 → match.
        $tracked = [['album_id' => 200, 'name' => 'auto']];
        $files = [200 => [1, 2, 3, 4, 5, 6]];
        $nameUpdates = [];
        $renameUpdates = [];
        $creator = $this->makeAlbumCreator($tracked, $files, $nameUpdates, $renameUpdates);

        $snapshot = [['album_id' => 1, 'custom_name' => 'Sabbatical', 'file_ids' => [1, 2, 3, 4]]];
        $report = $this->reassigner($creator)->reassign('alice', $snapshot);

        $this->assertCount(1, $report['matched']);
        $this->assertSame(200, $report['matched'][0]['album_id']);
        $this->assertSame('Sabbatical', $nameUpdates[200]);
    }

    public function testSplitAssignsToLargerOverlap(): void {
        // Old cluster [1..10]; new clusters [1..7] and [8..10].
        // Jaccard with [1..7]: 7/10 = 0.7 → match. With [8..10]: 3/10 = 0.3 → drop.
        $tracked = [
            ['album_id' => 301, 'name' => 'a'],
            ['album_id' => 302, 'name' => 'b'],
        ];
        $files = [
            301 => [1, 2, 3, 4, 5, 6, 7],
            302 => [8, 9, 10],
        ];
        $nameUpdates = [];
        $renameUpdates = [];
        $creator = $this->makeAlbumCreator($tracked, $files, $nameUpdates, $renameUpdates);

        $snapshot = [['album_id' => 1, 'custom_name' => 'Family reunion', 'file_ids' => range(1, 10)]];
        $report = $this->reassigner($creator)->reassign('alice', $snapshot);

        $this->assertCount(1, $report['matched']);
        $this->assertSame(301, $report['matched'][0]['album_id']);
        $this->assertSame('Family reunion', $nameUpdates[301]);
        $this->assertArrayNotHasKey(302, $nameUpdates);
    }

    public function testCompetingNamesGreedilyAssignedToBestNewAlbum(): void {
        // Two old names. Album 401 overlaps strongly with snapshot A, weakly with B.
        // Album 402 overlaps strongly with snapshot B, weakly with A.
        $tracked = [
            ['album_id' => 401, 'name' => 'a'],
            ['album_id' => 402, 'name' => 'b'],
        ];
        $files = [
            401 => [1, 2, 3, 4],          // ≈ snapshot A
            402 => [10, 11, 12, 13],      // ≈ snapshot B
        ];
        $nameUpdates = [];
        $renameUpdates = [];
        $creator = $this->makeAlbumCreator($tracked, $files, $nameUpdates, $renameUpdates);

        $snapshot = [
            ['album_id' => 1, 'custom_name' => 'A', 'file_ids' => [1, 2, 3, 4]],
            ['album_id' => 2, 'custom_name' => 'B', 'file_ids' => [10, 11, 12, 13]],
        ];
        $report = $this->reassigner($creator)->reassign('alice', $snapshot);

        $this->assertCount(2, $report['matched']);
        $this->assertSame('A', $nameUpdates[401]);
        $this->assertSame('B', $nameUpdates[402]);
    }

    public function testBelowThresholdDropped(): void {
        // Old [1..10]; new [1,2,11,12,13,14,15,16,17,18]. Intersection 2, union 18 → 0.111.
        $tracked = [['album_id' => 500, 'name' => 'auto']];
        $files = [500 => [1, 2, 11, 12, 13, 14, 15, 16, 17, 18]];
        $nameUpdates = [];
        $renameUpdates = [];
        $creator = $this->makeAlbumCreator($tracked, $files, $nameUpdates, $renameUpdates);

        $snapshot = [['album_id' => 1, 'custom_name' => 'Hanukkah', 'file_ids' => range(1, 10)]];
        $report = $this->reassigner($creator)->reassign('alice', $snapshot);

        $this->assertSame([], $report['matched']);
        $this->assertCount(1, $report['dropped']);
        $this->assertSame('Hanukkah', $report['dropped'][0]['old_name']);
        $this->assertArrayNotHasKey(500, $nameUpdates);
    }

    public function testEmptySnapshotIsNoop(): void {
        $tracked = [['album_id' => 600, 'name' => 'a']];
        $files = [600 => [1, 2, 3]];
        $nameUpdates = [];
        $renameUpdates = [];
        $creator = $this->makeAlbumCreator($tracked, $files, $nameUpdates, $renameUpdates);

        $report = $this->reassigner($creator)->reassign('alice', []);
        $this->assertSame([], $report['matched']);
        $this->assertSame([], $report['dropped']);
    }
}
