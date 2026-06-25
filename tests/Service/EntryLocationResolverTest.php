<?php
namespace OCA\Journeys\Tests\Service;

use OCA\Journeys\Service\EntryLocationResolver;
use PHPUnit\Framework\TestCase;

class EntryLocationResolverTest extends TestCase {

    public function testCentroidOfEmptyIsNull(): void {
        $this->assertNull(EntryLocationResolver::centroid([]));
    }

    public function testCentroidSinglePoint(): void {
        $this->assertSame([43.77, 11.25], EntryLocationResolver::centroid([[43.77, 11.25]]));
    }

    public function testCentroidAverages(): void {
        $c = EntryLocationResolver::centroid([[0.0, 0.0], [10.0, 20.0], [-4.0, 4.0]]);
        $this->assertEqualsWithDelta(2.0, $c[0], 1e-9);
        $this->assertEqualsWithDelta(8.0, $c[1], 1e-9);
    }

    public function testMedoidOfEmptyIsNull(): void {
        $this->assertNull(EntryLocationResolver::medoid([]));
    }

    public function testMedoidSinglePoint(): void {
        $this->assertSame([43.77, 11.25], EntryLocationResolver::medoid([[43.77, 11.25]]));
    }

    public function testMedoidIsAlwaysAnInputPoint(): void {
        // Photos on opposite shores of a bay: the mean lands in the water
        // between them, but the medoid must be one of the actual points.
        $points = [[35.337, 25.087], [35.207, 26.106], [35.125, 25.743]];
        $medoid = EntryLocationResolver::medoid($points);
        $this->assertContains($medoid, $points);
    }

    public function testMedoidPicksAPointInTheDenseCluster(): void {
        // Five tightly-clustered points plus one far outlier: the medoid stays
        // in the cluster (closest to everything), never the outlier.
        $cluster = [[10.00, 10.00], [10.01, 10.00], [10.00, 10.01], [9.99, 10.00], [10.00, 9.99]];
        $points = array_merge($cluster, [[50.0, 50.0]]);
        $medoid = EntryLocationResolver::medoid($points);
        $this->assertContains($medoid, $cluster);
        $this->assertNotSame([50.0, 50.0], $medoid);
    }
}
