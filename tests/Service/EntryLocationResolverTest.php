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
}
