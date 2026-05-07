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

    public function testMultiCityTripPrefersCityOverArrondissement(): void {
        // Two stops: Paris (city level 8) with arrondissement level 9, and
        // Frankfurt am Main (city level 6 — kreisfreie Stadt) with Stadtteil
        // level 10. We expect "Paris" / "Frankfurt am Main", not "13e" / "Süd".
        $place = $this->createMock(\OCA\Journeys\Service\SimplePlaceResolver::class);
        $place->method('queryPoint')->willReturnCallback(function (float $lat) {
            if (abs($lat - 48.85) < 0.01) {
                return [
                    ['osm_id' => 1, 'admin_level' => 8, 'name' => 'Paris'],
                    ['osm_id' => 2, 'admin_level' => 9, 'name' => 'Paris 13e Arrondissement'],
                ];
            }
            return [
                ['osm_id' => 3, 'admin_level' => 6, 'name' => 'Frankfurt am Main'],
                ['osm_id' => 4, 'admin_level' => 10, 'name' => 'Süd'],
            ];
        });
        $resolver = new VideoSubtitleResolver($place);
        $images = [
            new \OCA\Journeys\Model\Image(1, '', '', '48.85', '2.35'),
            new \OCA\Journeys\Model\Image(2, '', '', '48.85', '2.35'),
            new \OCA\Journeys\Model\Image(3, '', '', '50.11', '8.68'),
            new \OCA\Journeys\Model\Image(4, '', '', '50.11', '8.68'),
        ];
        $files = ['/tmp/01.jpg', '/tmp/02.jpg', '/tmp/03.jpg', '/tmp/04.jpg'];
        $map = $resolver->buildBasenameMap($files, $images);
        $this->assertSame([
            '01' => 'Paris',
            '02' => 'Paris',
            '03' => 'Frankfurt am Main',
            '04' => 'Frankfurt am Main',
        ], $map);
    }

    public function testSingleCityTripFallsBackToSuburbForIntraCityVariation(): void {
        // All photos in Paris but in different arrondissements — we want the
        // arrondissement labels so the user still gets caption changes.
        $place = $this->createMock(\OCA\Journeys\Service\SimplePlaceResolver::class);
        $place->method('queryPoint')->willReturnCallback(function (float $lat, float $lon) {
            $arr = $lon > 2.36 ? '13e' : '1er';
            return [
                ['osm_id' => 1, 'admin_level' => 8, 'name' => 'Paris'],
                ['osm_id' => 2, 'admin_level' => 9, 'name' => 'Paris ' . $arr . ' Arrondissement'],
            ];
        });
        $resolver = new VideoSubtitleResolver($place);
        $images = [
            new \OCA\Journeys\Model\Image(1, '', '', '48.86', '2.34'),
            new \OCA\Journeys\Model\Image(2, '', '', '48.86', '2.34'),
            new \OCA\Journeys\Model\Image(3, '', '', '48.83', '2.37'),
            new \OCA\Journeys\Model\Image(4, '', '', '48.83', '2.37'),
        ];
        $files = ['/tmp/01.jpg', '/tmp/02.jpg', '/tmp/03.jpg', '/tmp/04.jpg'];
        $map = $resolver->buildBasenameMap($files, $images);
        $this->assertSame([
            '01' => 'Paris 1er Arrondissement',
            '02' => 'Paris 1er Arrondissement',
            '03' => 'Paris 13e Arrondissement',
            '04' => 'Paris 13e Arrondissement',
        ], $map);
    }

    public function testParisAndFrankfurtJourneyCollapsesToCityNames(): void {
        // Mirrors tools/probe_subtitles_synthetic.php: a 12-photo journey
        // crossing 3 Paris arrondissements and 3 Frankfurt Stadtteile. We
        // expect every photo to be captioned with the city, not the suburb.
        // Mocked queryPoint returns the same admin-level stack a real
        // Nominatim/Memories planet lookup produces for these coordinates.
        $place = $this->createMock(\OCA\Journeys\Service\SimplePlaceResolver::class);
        $place->method('queryPoint')->willReturnCallback(static function (float $lat, float $lon): array {
            // Paris: city sits at admin_level=8, arrondissements at 9.
            if ($lat > 48.0 && $lat < 49.0) {
                $arr = match (true) {
                    $lat < 48.85 => '13e',
                    $lon < 2.32  => '8e',
                    default      => '1er',
                };
                return [
                    ['osm_id' => 1, 'admin_level' => 2, 'name' => 'France'],
                    ['osm_id' => 2, 'admin_level' => 4, 'name' => 'Île-de-France'],
                    ['osm_id' => 3, 'admin_level' => 6, 'name' => 'Paris'],
                    ['osm_id' => 4, 'admin_level' => 8, 'name' => 'Paris'],
                    ['osm_id' => 5, 'admin_level' => 9, 'name' => 'Paris ' . $arr . ' Arrondissement'],
                ];
            }
            // Frankfurt am Main: kreisfreie Stadt at admin_level=6, no level 8;
            // Stadtbezirke at level 9, Stadtteile at level 10.
            $stadtteil = match (true) {
                $lat < 50.105 => 'Sachsenhausen-Nord',
                $lat < 50.115 => 'Innenstadt',
                default       => 'Bornheim',
            };
            $bezirk = $stadtteil === 'Bornheim' ? 'Bornheim/Ostend' : 'Innenstadt I';
            return [
                ['osm_id' => 10, 'admin_level' => 2, 'name' => 'Deutschland'],
                ['osm_id' => 11, 'admin_level' => 4, 'name' => 'Hessen'],
                ['osm_id' => 12, 'admin_level' => 5, 'name' => 'Regierungsbezirk Darmstadt'],
                ['osm_id' => 13, 'admin_level' => 6, 'name' => 'Frankfurt am Main'],
                ['osm_id' => 14, 'admin_level' => 9, 'name' => $bezirk],
                ['osm_id' => 15, 'admin_level' => 10, 'name' => $stadtteil],
            ];
        });
        $resolver = new VideoSubtitleResolver($place);

        $stops = [
            ['Paris 1er a',  48.8606, 2.3376],
            ['Paris 1er b',  48.8607, 2.3377],
            ['Paris 8e a',   48.8698, 2.3076],
            ['Paris 8e b',   48.8699, 2.3077],
            ['Paris 13e a',  48.8323, 2.3559],
            ['Paris 13e b',  48.8324, 2.3560],
            ['Sachsenh. a',  50.1042, 8.6857],
            ['Sachsenh. b',  50.1043, 8.6858],
            ['Innenstadt a', 50.1109, 8.6821],
            ['Innenstadt b', 50.1110, 8.6822],
            ['Bornheim a',   50.1289, 8.6989],
            ['Bornheim b',   50.1290, 8.6990],
        ];
        $images = [];
        $files = [];
        foreach ($stops as $i => $s) {
            $images[] = new \OCA\Journeys\Model\Image(1000 + $i, '', '', (string)$s[1], (string)$s[2]);
            $files[] = sprintf('/tmp/%02d.jpg', $i + 1);
        }
        $map = $resolver->buildBasenameMap($files, $images);

        $expected = [];
        foreach (range(1, 6) as $n) {
            $expected[sprintf('%02d', $n)] = 'Paris';
        }
        foreach (range(7, 12) as $n) {
            $expected[sprintf('%02d', $n)] = 'Frankfurt am Main';
        }
        $this->assertSame($expected, $map);
    }

    public function testSmallCityJourneyUsesPlainCityNames(): void {
        // Nantes and Nice are simple admin_level=8 communes without
        // arrondissements/Stadtteile in OSM. The resolver should just emit
        // their city names regardless of how the city/suburb branch chose.
        $place = $this->createMock(\OCA\Journeys\Service\SimplePlaceResolver::class);
        $place->method('queryPoint')->willReturnCallback(static function (float $lat): array {
            if ($lat > 47.0 && $lat < 47.5) {
                return [
                    ['osm_id' => 100, 'admin_level' => 2, 'name' => 'France'],
                    ['osm_id' => 101, 'admin_level' => 4, 'name' => 'Pays de la Loire'],
                    ['osm_id' => 102, 'admin_level' => 6, 'name' => 'Loire-Atlantique'],
                    ['osm_id' => 103, 'admin_level' => 8, 'name' => 'Nantes'],
                ];
            }
            return [
                ['osm_id' => 200, 'admin_level' => 2, 'name' => 'France'],
                ['osm_id' => 201, 'admin_level' => 4, 'name' => 'Provence-Alpes-Côte d\'Azur'],
                ['osm_id' => 202, 'admin_level' => 6, 'name' => 'Alpes-Maritimes'],
                ['osm_id' => 203, 'admin_level' => 8, 'name' => 'Nice'],
            ];
        });
        $resolver = new VideoSubtitleResolver($place);

        $stops = [
            [47.2184, -1.5536], [47.2185, -1.5537],   // Nantes
            [47.2110, -1.5500], [47.2111, -1.5501],   // Nantes (different point)
            [43.7034, 7.2663],  [43.7035, 7.2664],    // Nice (Vieux-Nice)
            [43.6961, 7.2716],  [43.6962, 7.2717],    // Nice (Promenade)
        ];
        $images = [];
        $files = [];
        foreach ($stops as $i => $s) {
            $images[] = new \OCA\Journeys\Model\Image(3000 + $i, '', '', (string)$s[0], (string)$s[1]);
            $files[] = sprintf('/tmp/s%02d.jpg', $i + 1);
        }
        $map = $resolver->buildBasenameMap($files, $images);

        $this->assertSame([
            's01' => 'Nantes', 's02' => 'Nantes',
            's03' => 'Nantes', 's04' => 'Nantes',
            's05' => 'Nice',   's06' => 'Nice',
            's07' => 'Nice',   's08' => 'Nice',
        ], $map);
    }

    public function testMixedBigAndSmallCityTripUsesCityNamesForBoth(): void {
        // Paris (has level-9 arrondissements) + Nice (only level 8). With
        // 2+ distinct cities the resolver picks city names everywhere — the
        // arrondissement must not leak into the Paris captions.
        $place = $this->createMock(\OCA\Journeys\Service\SimplePlaceResolver::class);
        $place->method('queryPoint')->willReturnCallback(static function (float $lat, float $lon): array {
            if ($lat > 48.0 && $lat < 49.0) {
                $arr = $lat < 48.85 ? '13e' : ($lon < 2.32 ? '8e' : '1er');
                return [
                    ['osm_id' => 3, 'admin_level' => 6, 'name' => 'Paris'],
                    ['osm_id' => 4, 'admin_level' => 8, 'name' => 'Paris'],
                    ['osm_id' => 5, 'admin_level' => 9, 'name' => 'Paris ' . $arr . ' Arrondissement'],
                ];
            }
            return [
                ['osm_id' => 202, 'admin_level' => 6, 'name' => 'Alpes-Maritimes'],
                ['osm_id' => 203, 'admin_level' => 8, 'name' => 'Nice'],
            ];
        });
        $resolver = new VideoSubtitleResolver($place);

        $stops = [
            [48.8606, 2.3376], [48.8607, 2.3377],   // Paris 1er
            [48.8323, 2.3559], [48.8324, 2.3560],   // Paris 13e
            [43.7034, 7.2663], [43.7035, 7.2664],   // Nice
            [43.6961, 7.2716], [43.6962, 7.2717],   // Nice
        ];
        $images = [];
        $files = [];
        foreach ($stops as $i => $s) {
            $images[] = new \OCA\Journeys\Model\Image(4000 + $i, '', '', (string)$s[0], (string)$s[1]);
            $files[] = sprintf('/tmp/m%02d.jpg', $i + 1);
        }
        $map = $resolver->buildBasenameMap($files, $images);

        $this->assertSame([
            'm01' => 'Paris', 'm02' => 'Paris',
            'm03' => 'Paris', 'm04' => 'Paris',
            'm05' => 'Nice',  'm06' => 'Nice',
            'm07' => 'Nice',  'm08' => 'Nice',
        ], $map);
    }

    public function testParisOnlyJourneyKeepsArrondissementDetail(): void {
        // Same Paris OSM shape as the multi-city test; with no second city,
        // the resolver must fall back to suburb-level so each arrondissement
        // still produces a distinct caption.
        $place = $this->createMock(\OCA\Journeys\Service\SimplePlaceResolver::class);
        $place->method('queryPoint')->willReturnCallback(static function (float $lat, float $lon): array {
            $arr = match (true) {
                $lat < 48.85 => '13e',
                $lon < 2.32  => '8e',
                default      => '1er',
            };
            return [
                ['osm_id' => 3, 'admin_level' => 6, 'name' => 'Paris'],
                ['osm_id' => 4, 'admin_level' => 8, 'name' => 'Paris'],
                ['osm_id' => 5, 'admin_level' => 9, 'name' => 'Paris ' . $arr . ' Arrondissement'],
            ];
        });
        $resolver = new VideoSubtitleResolver($place);

        $stops = [
            [48.8606, 2.3376], [48.8607, 2.3377],
            [48.8698, 2.3076], [48.8699, 2.3077],
            [48.8323, 2.3559], [48.8324, 2.3560],
        ];
        $images = [];
        $files = [];
        foreach ($stops as $i => $s) {
            $images[] = new \OCA\Journeys\Model\Image(2000 + $i, '', '', (string)$s[0], (string)$s[1]);
            $files[] = sprintf('/tmp/p%02d.jpg', $i + 1);
        }
        $map = $resolver->buildBasenameMap($files, $images);

        $this->assertSame([
            'p01' => 'Paris 1er Arrondissement',
            'p02' => 'Paris 1er Arrondissement',
            'p03' => 'Paris 8e Arrondissement',
            'p04' => 'Paris 8e Arrondissement',
            'p05' => 'Paris 13e Arrondissement',
            'p06' => 'Paris 13e Arrondissement',
        ], $map);
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
