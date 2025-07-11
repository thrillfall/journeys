<?php
namespace OCA\Journeys\Tests\Service;

use OCA\Journeys\Model\Image;
use OCA\Journeys\Service\Clusterer;
use PHPUnit\Framework\TestCase;

class ClustererTest extends TestCase {
    public function testClusterImagesWithAndWithoutLocation() {
        $images = [
            new Image(1, 'a.jpg', '2024-01-01 10:00:00', 50.0, 8.0),
            new Image(2, 'b.jpg', '2024-01-01 10:10:00', null, null), // no location
            new Image(3, 'c.jpg', '2024-01-01 10:20:00', 50.0005, 8.0005), // close in space/time
            new Image(4, 'd.jpg', '2024-01-02 12:00:00', 51.0, 9.0), // far in time/space
            new Image(5, 'e.jpg', '2024-01-02 12:10:00', null, null), // no location, close in time
        ];
        // Sort by datetaken (should already be sorted, but for realism)
        usort($images, function($a, $b) {
            return strtotime($a->datetaken) <=> strtotime($b->datetaken);
        });
        $clusterer = new Clusterer();
        $clusters = $clusterer->clusterImages($images, 3600, 2.0); // 1 hour, 2km
        $this->assertCount(2, $clusters);
        $this->assertCount(3, $clusters[0]); // first 3 images clustered
        $this->assertCount(2, $clusters[1]); // last 2 images clustered
        $this->assertEquals('a.jpg', $clusters[0][0]->path);
        $this->assertEquals('e.jpg', $clusters[1][1]->path);
    }
}
