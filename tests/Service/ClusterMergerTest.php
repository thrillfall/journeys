<?php
namespace OCA\Journeys\Tests\Service;

use OCA\Journeys\Model\Image;
use OCA\Journeys\Service\ClusterMerger;
use PHPUnit\Framework\TestCase;

class ClusterMergerTest extends TestCase {

    private ClusterMerger $merger;

    protected function setUp(): void {
        $this->merger = new ClusterMerger();
    }

    /**
     * Country resolver that returns the country of the first geolocated image
     * via an externally-provided [lat,lon => country] lookup. Lets tests shape
     * geographic coherence without touching any DB.
     *
     * @param array<string,string> $map "lat,lon" => country
     */
    private function countryResolver(array $map): callable {
        return function(array $cluster) use ($map): ?string {
            foreach ($cluster as $img) {
                if ($img->lat === null || $img->lon === null) continue;
                $key = $img->lat . ',' . $img->lon;
                if (isset($map[$key])) return $map[$key];
            }
            return null;
        };
    }

    private function img(int $id, string $datetaken, ?string $lat = null, ?string $lon = null): Image {
        return new Image($id, "img{$id}.jpg", $datetaken, $lat, $lon);
    }

    public function testEmptyInputReturnsEmpty(): void {
        $this->assertSame([], $this->merger->mergeAdjacent([], null, fn() => 'FR'));
    }

    public function testSingleClusterReturnedUnchanged(): void {
        $clusters = [[ $this->img(1, '2024-07-01 10:00:00', '48.8', '2.3') ]];
        $result = $this->merger->mergeAdjacent($clusters, null, fn() => 'FR');
        $this->assertCount(1, $result);
        $this->assertCount(1, $result[0]);
    }

    public function testNullCountryResolverDisablesMerging(): void {
        $clusters = [
            [ $this->img(1, '2024-07-01 10:00:00', '48.8', '2.3') ],
            [ $this->img(2, '2024-07-02 10:00:00', '43.3', '5.4') ],
        ];
        $result = $this->merger->mergeAdjacent($clusters, null, null);
        $this->assertCount(2, $result);
    }

    public function testMultiCityFrenchRoadTripMergesToOne(): void {
        // Paris → Lyon → Marseille → Nice across 6 days, all France
        $paris = ['48.8', '2.3'];
        $lyon = ['45.7', '4.8'];
        $marseille = ['43.3', '5.4'];
        $nice = ['43.7', '7.2'];
        $clusters = [
            [ $this->img(1, '2024-07-01 10:00:00', $paris[0], $paris[1]),
              $this->img(2, '2024-07-01 18:00:00', $paris[0], $paris[1]) ],
            [ $this->img(3, '2024-07-02 11:00:00', $lyon[0], $lyon[1]),
              $this->img(4, '2024-07-02 19:00:00', $lyon[0], $lyon[1]) ],
            [ $this->img(5, '2024-07-04 09:00:00', $marseille[0], $marseille[1]),
              $this->img(6, '2024-07-04 20:00:00', $marseille[0], $marseille[1]) ],
            [ $this->img(7, '2024-07-06 08:00:00', $nice[0], $nice[1]),
              $this->img(8, '2024-07-06 21:00:00', $nice[0], $nice[1]) ],
        ];
        $resolver = $this->countryResolver([
            '48.8,2.3' => 'France',
            '45.7,4.8' => 'France',
            '43.3,5.4' => 'France',
            '43.7,7.2' => 'France',
        ]);
        $result = $this->merger->mergeAdjacent($clusters, null, $resolver);
        $this->assertCount(1, $result);
        $this->assertCount(8, $result[0]);
    }

    public function testNewZealandRestDayGapsMerge(): void {
        // NZ vacation with 2-day blank gaps between photo-taking days, all NZ
        $auckland = ['-36.8', '174.7'];
        $rotorua = ['-38.1', '176.2'];
        $wellington = ['-41.3', '174.8'];
        $clusters = [
            [ $this->img(1, '2024-10-10 10:00:00', $auckland[0], $auckland[1]) ],
            [ $this->img(2, '2024-10-13 10:00:00', $rotorua[0], $rotorua[1]) ],
            [ $this->img(3, '2024-10-16 10:00:00', $wellington[0], $wellington[1]) ],
        ];
        $resolver = $this->countryResolver([
            '-36.8,174.7' => 'New Zealand',
            '-38.1,176.2' => 'New Zealand',
            '-41.3,174.8' => 'New Zealand',
        ]);
        $result = $this->merger->mergeAdjacent($clusters, null, $resolver);
        $this->assertCount(1, $result);
        $this->assertCount(3, $result[0]);
    }

    public function testSeasonallySeparatedTripsDoNotMerge(): void {
        // Paris in January, Berlin in June — gap >> 7 days, must stay separate
        $clusters = [
            [ $this->img(1, '2024-01-15 12:00:00', '48.8', '2.3') ],
            [ $this->img(2, '2024-06-20 12:00:00', '52.5', '13.4') ],
        ];
        $resolver = $this->countryResolver([
            '48.8,2.3' => 'France',
            '52.5,13.4' => 'Germany',
        ]);
        $result = $this->merger->mergeAdjacent($clusters, null, $resolver);
        $this->assertCount(2, $result);
    }

    public function testDifferentCountriesWithinWindowDoNotMerge(): void {
        // Paris then Tokyo, 2 days apart. Plausible velocity, but not same country.
        $clusters = [
            [ $this->img(1, '2024-07-01 10:00:00', '48.8', '2.3') ],
            [ $this->img(2, '2024-07-03 10:00:00', '35.7', '139.7') ],
        ];
        $resolver = $this->countryResolver([
            '48.8,2.3' => 'France',
            '35.7,139.7' => 'Japan',
        ]);
        $result = $this->merger->mergeAdjacent($clusters, null, $resolver);
        $this->assertCount(2, $result);
    }

    public function testNearHomeIntrusionPreventsMerge(): void {
        // away cluster A (Spain) → near-home cluster (Berlin home) → away cluster B (Spain)
        // The near-home cluster is in the middle. Even though A and B are both "Spain",
        // they are not adjacent — the near-home cluster sits between them and is not
        // eligible to merge with either side (mixed home state).
        $home = ['lat' => 52.5, 'lon' => 13.4, 'radiusKm' => 50.0];
        $barcelona = ['41.4', '2.2'];
        $madrid = ['40.4', '-3.7'];
        $berlin = ['52.5', '13.4'];
        $clusters = [
            [ $this->img(1, '2024-07-01 10:00:00', $barcelona[0], $barcelona[1]) ],
            [ $this->img(2, '2024-07-05 10:00:00', $berlin[0], $berlin[1]) ],
            [ $this->img(3, '2024-07-09 10:00:00', $madrid[0], $madrid[1]) ],
        ];
        $resolver = $this->countryResolver([
            '41.4,2.2' => 'Spain',
            '40.4,-3.7' => 'Spain',
            '52.5,13.4' => 'Germany',
        ]);
        $result = $this->merger->mergeAdjacent($clusters, $home, $resolver);
        $this->assertCount(3, $result);
    }

    public function testClusterWithoutGeolocationDoesNotMergeIntoNeighbors(): void {
        $clusters = [
            [ $this->img(1, '2024-07-01 10:00:00', '48.8', '2.3') ],
            [ $this->img(2, '2024-07-02 10:00:00', null, null) ], // no geo at all
            [ $this->img(3, '2024-07-03 10:00:00', '48.8', '2.3') ],
        ];
        $resolver = $this->countryResolver(['48.8,2.3' => 'France']);
        $result = $this->merger->mergeAdjacent($clusters, null, $resolver);
        // Middle cluster has no geolocated image → can't merge with either neighbor.
        // But outer clusters are now non-adjacent so they can't merge either.
        $this->assertCount(3, $result);
    }

    public function testFixpointMergesChainOfThree(): void {
        // A, B, C all in France, each pair within 3 days. Pass 1 merges A+B → AB.
        // Pass 2 should then merge AB+C.
        $clusters = [
            [ $this->img(1, '2024-07-01 10:00:00', '48.8', '2.3') ],
            [ $this->img(2, '2024-07-03 10:00:00', '45.7', '4.8') ],
            [ $this->img(3, '2024-07-06 10:00:00', '43.3', '5.4') ],
        ];
        $resolver = $this->countryResolver([
            '48.8,2.3' => 'France',
            '45.7,4.8' => 'France',
            '43.3,5.4' => 'France',
        ]);
        $result = $this->merger->mergeAdjacent($clusters, null, $resolver);
        $this->assertCount(1, $result);
        $this->assertCount(3, $result[0]);
    }

    public function testGapExceedingLimitPreventsMerge(): void {
        // Same country but >7-day gap → must stay separate
        $clusters = [
            [ $this->img(1, '2024-07-01 10:00:00', '48.8', '2.3') ],
            [ $this->img(2, '2024-07-10 10:00:00', '43.3', '5.4') ],
        ];
        $resolver = $this->countryResolver([
            '48.8,2.3' => 'France',
            '43.3,5.4' => 'France',
        ]);
        $result = $this->merger->mergeAdjacent($clusters, null, $resolver);
        $this->assertCount(2, $result);
    }

    public function testCountryMismatchEmitsNoMergeEvent(): void {
        $clusters = [
            [ $this->img(1, '2024-07-01 10:00:00', '48.8', '2.3') ],
            [ $this->img(2, '2024-07-02 10:00:00', '35.7', '139.7') ],
        ];
        $resolver = $this->countryResolver([
            '48.8,2.3' => 'France',
            '35.7,139.7' => 'Japan',
        ]);
        $events = [];
        $this->merger->mergeAdjacent($clusters, null, $resolver, function(array $ev) use (&$events) {
            $events[] = $ev;
        });
        $this->assertCount(1, $events);
        $this->assertSame('no_merge', $events[0]['type']);
        $this->assertSame('country_mismatch', $events[0]['reason']);
        $this->assertSame('France', $events[0]['country_a']);
        $this->assertSame('Japan', $events[0]['country_b']);
    }

    public function testCountryNullOnOneSideEmitsNoMergeEvent(): void {
        // Mirrors the real-world Napier issue: middle cluster's country resolves to null
        // while its neighbors resolve to the same country.
        $clusters = [
            [ $this->img(1, '2024-11-09 10:00:00', '-36.8', '174.7') ],
            [ $this->img(2, '2024-11-10 10:00:00', '-39.5', '176.9') ],
        ];
        $resolver = $this->countryResolver([
            '-36.8,174.7' => 'New Zealand',
            // '-39.5,176.9' intentionally unmapped → resolver returns null
        ]);
        $events = [];
        $this->merger->mergeAdjacent($clusters, null, $resolver, function(array $ev) use (&$events) {
            $events[] = $ev;
        });
        $this->assertCount(1, $events);
        $this->assertSame('no_merge', $events[0]['type']);
        $this->assertSame('country_null_b', $events[0]['reason']);
        $this->assertSame('New Zealand', $events[0]['country_a']);
        $this->assertNull($events[0]['country_b']);
    }

    public function testTinyNoiseClusterAbsorbedBetweenTwoSameCountryClusters(): void {
        // Real-world NZ case: 1-image GPS-glitch cluster at Hong Kong coords
        // sits between two large New Zealand clusters one day apart. With
        // minClusterSize=7 the noise cluster is below threshold and must be
        // absorbed so the two legs merge.
        $nzA = ['-37.0', '174.7'];
        $hkGlitch = ['22.3', '114.1'];
        $nzB = ['-39.5', '176.9'];
        $clusters = [
            [ $this->img(1, '2024-11-10 12:00:00', $nzA[0], $nzA[1]) ],
            [ $this->img(2, '2024-11-10 12:40:00', $hkGlitch[0], $hkGlitch[1]) ],
            [ $this->img(3, '2024-11-10 12:50:00', $nzB[0], $nzB[1]) ],
        ];
        $resolver = $this->countryResolver([
            '-37.0,174.7' => 'New Zealand/Aotearoa',
            '22.3,114.1' => '中国',
            '-39.5,176.9' => 'New Zealand/Aotearoa',
        ]);
        $result = $this->merger->mergeAdjacent($clusters, null, $resolver, null, ClusterMerger::MAX_MERGE_GAP_DAYS, 7);
        $this->assertCount(1, $result, 'all three clusters should merge into one');
        $this->assertCount(3, $result[0], 'noise image should be preserved, not dropped');
    }

    public function testNoiseAbsorptionEmitsMergeThroughNoiseEvent(): void {
        $clusters = [
            [ $this->img(1, '2024-11-10 12:00:00', '-37.0', '174.7') ],
            [ $this->img(2, '2024-11-10 12:40:00', '22.3', '114.1') ],
            [ $this->img(3, '2024-11-10 12:50:00', '-39.5', '176.9') ],
        ];
        $resolver = $this->countryResolver([
            '-37.0,174.7' => 'New Zealand/Aotearoa',
            '22.3,114.1' => '中国',
            '-39.5,176.9' => 'New Zealand/Aotearoa',
        ]);
        $events = [];
        $this->merger->mergeAdjacent($clusters, null, $resolver, function(array $ev) use (&$events) {
            $events[] = $ev;
        }, ClusterMerger::MAX_MERGE_GAP_DAYS, 7);
        $mergeEvents = array_values(array_filter($events, fn($e) => $e['type'] === 'merge'));
        $this->assertCount(1, $mergeEvents);
        $this->assertSame('same_country_through_noise', $mergeEvents[0]['reason']);
        $this->assertSame(1, $mergeEvents[0]['noise_size']);
        $this->assertSame('New Zealand/Aotearoa', $mergeEvents[0]['country']);
    }

    public function testLargeMiddleClusterIsNotTreatedAsNoise(): void {
        // When B >= minClusterSize, it's a genuine stopover and must still block
        // A+C from merging through it.
        $clusters = [
            [ $this->img(1, '2024-07-01 10:00:00', '48.8', '2.3') ],
            [ $this->img(2, '2024-07-02 10:00:00', '52.5', '13.4'),
              $this->img(3, '2024-07-02 18:00:00', '52.5', '13.4') ],
            [ $this->img(4, '2024-07-03 10:00:00', '45.7', '4.8') ],
        ];
        $resolver = $this->countryResolver([
            '48.8,2.3' => 'France',
            '52.5,13.4' => 'Germany',
            '45.7,4.8' => 'France',
        ]);
        $result = $this->merger->mergeAdjacent($clusters, null, $resolver, null, ClusterMerger::MAX_MERGE_GAP_DAYS, 2);
        $this->assertCount(3, $result);
    }

    public function testNoiseAbsorptionRespectsTimeWindow(): void {
        // Even if middle cluster is tiny, A-end → C-start must still be within
        // maxMergeGapDays.
        $clusters = [
            [ $this->img(1, '2024-07-01 10:00:00', '-37.0', '174.7') ],
            [ $this->img(2, '2024-07-05 10:00:00', '22.3', '114.1') ],
            [ $this->img(3, '2024-07-15 10:00:00', '-39.5', '176.9') ], // >7d from A end
        ];
        $resolver = $this->countryResolver([
            '-37.0,174.7' => 'New Zealand',
            '22.3,114.1' => 'China',
            '-39.5,176.9' => 'New Zealand',
        ]);
        $result = $this->merger->mergeAdjacent($clusters, null, $resolver, null, ClusterMerger::MAX_MERGE_GAP_DAYS, 7);
        $this->assertCount(3, $result, 'time window must block absorption even across noise');
    }

    public function testMergeDebugCallbackReceivesPayload(): void {
        $clusters = [
            [ $this->img(1, '2024-07-01 10:00:00', '48.8', '2.3') ],
            [ $this->img(2, '2024-07-02 10:00:00', '43.3', '5.4') ],
        ];
        $resolver = $this->countryResolver([
            '48.8,2.3' => 'France',
            '43.3,5.4' => 'France',
        ]);
        $events = [];
        $this->merger->mergeAdjacent($clusters, null, $resolver, function(array $ev) use (&$events) {
            $events[] = $ev;
        });
        $this->assertCount(1, $events);
        $this->assertSame('merge', $events[0]['type']);
        $this->assertSame('same_country', $events[0]['reason']);
        $this->assertSame('France', $events[0]['country']);
    }
}
