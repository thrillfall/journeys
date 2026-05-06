<?php
namespace OCA\Journeys\Tests\Service;

use OCA\Journeys\Service\VideoSubtitleResolver;
use PHPUnit\Framework\TestCase;

class VideoSubtitleResolverSmoothingTest extends TestCase {
    public function testKeepsLongRuns(): void {
        $in = ['A', 'A', 'A', 'B', 'B', 'B'];
        $this->assertSame($in, VideoSubtitleResolver::smooth($in, 2));
    }

    public function testIslandSurroundedBySameNameAdoptsThatName(): void {
        $in  = ['A', 'A', 'A', 'B', 'A', 'A', 'A'];
        $out = ['A', 'A', 'A', 'A', 'A', 'A', 'A'];
        $this->assertSame($out, VideoSubtitleResolver::smooth($in, 2));
    }

    public function testIslandAtBoundaryAdoptsLongerNeighbor(): void {
        $in  = ['B', 'A', 'A', 'A', 'A'];
        $out = ['A', 'A', 'A', 'A', 'A'];
        $this->assertSame($out, VideoSubtitleResolver::smooth($in, 2));
    }

    public function testNullEntriesAreNotSmoothedOver(): void {
        $in  = ['A', 'A', null, 'A', 'A'];
        $out = ['A', 'A', null, 'A', 'A'];
        $this->assertSame($out, VideoSubtitleResolver::smooth($in, 2));
    }

    public function testIsolatedNonNullBetweenNullsBecomesNull(): void {
        $in  = [null, 'A', null];
        $out = [null, null, null];
        $this->assertSame($out, VideoSubtitleResolver::smooth($in, 2));
    }

    public function testSwitchBetweenTwoLongRunsKeepsBoth(): void {
        $in  = ['A', 'A', 'A', 'B', 'B', 'B'];
        $this->assertSame($in, VideoSubtitleResolver::smooth($in, 2));
    }

    public function testBuildBasenameMapSuppressesSingleLocationJourney(): void {
        $resolver = $this->makeResolverReturningName('Auckland');
        $files = ['/tmp/00001.jpg', '/tmp/00002.jpg', '/tmp/00003.jpg'];
        $images = [
            new \OCA\Journeys\Model\Image(1, '', '', '-36.85', '174.76'),
            new \OCA\Journeys\Model\Image(2, '', '', '-36.85', '174.76'),
            new \OCA\Journeys\Model\Image(3, '', '', '-36.85', '174.76'),
        ];
        $this->assertSame([], $resolver->buildBasenameMap($files, $images));
    }

    public function testBuildBasenameMapKeepsTwoLocationJourney(): void {
        $resolver = $this->makeResolverWithLatToName(['-36.85' => 'Auckland', '-41.29' => 'Wellington']);
        $files = ['/tmp/00001.jpg', '/tmp/00002.jpg', '/tmp/00003.jpg', '/tmp/00004.jpg'];
        $images = [
            new \OCA\Journeys\Model\Image(1, '', '', '-36.85', '174.76'),
            new \OCA\Journeys\Model\Image(2, '', '', '-36.85', '174.76'),
            new \OCA\Journeys\Model\Image(3, '', '', '-41.29', '174.78'),
            new \OCA\Journeys\Model\Image(4, '', '', '-41.29', '174.78'),
        ];
        $map = $resolver->buildBasenameMap($files, $images);
        $this->assertSame(['00001' => 'Auckland', '00002' => 'Auckland', '00003' => 'Wellington', '00004' => 'Wellington'], $map);
    }

    private function makeResolverReturningName(string $name): VideoSubtitleResolver {
        $place = $this->createMock(\OCA\Journeys\Service\SimplePlaceResolver::class);
        $place->method('queryPoint')->willReturn([['osm_id' => 1, 'admin_level' => 8, 'name' => $name]]);
        return new VideoSubtitleResolver($place);
    }

    private function makeResolverWithLatToName(array $latToName): VideoSubtitleResolver {
        $place = $this->createMock(\OCA\Journeys\Service\SimplePlaceResolver::class);
        $place->method('queryPoint')->willReturnCallback(function (float $lat) use ($latToName) {
            $key = sprintf('%.2f', $lat);
            foreach ($latToName as $k => $name) {
                if (sprintf('%.2f', (float)$k) === $key) {
                    return [['osm_id' => 1, 'admin_level' => 8, 'name' => $name]];
                }
            }
            return [];
        });
        return new VideoSubtitleResolver($place);
    }

    public function testEmptyAndShortInputs(): void {
        $this->assertSame([], VideoSubtitleResolver::smooth([], 2));
        $this->assertSame(['A'], VideoSubtitleResolver::smooth(['A'], 2));
        $this->assertSame([null], VideoSubtitleResolver::smooth([null], 2));
    }
}
