<?php

declare(strict_types=1);

namespace OCA\Journeys\Tests\Service;

use OCA\Journeys\Service\ImageLocationInterpolator;
use OCA\Journeys\Model\Image;
use PHPUnit\Framework\TestCase;

class ImageLocationInterpolatorTest extends TestCase
{
    private function img($id, $date, $lat = null, $lon = null)
    {
        return new Image($id, '/img'.$id.'.jpg', $date, $lat, $lon);
    }

    public function testInterpolateBetweenTwoNeighborsWithinConstraints()
    {
        $a = $this->img(1, '2024-01-01 10:00:00', '52.0', '13.0');
        $b = $this->img(2, '2024-01-01 11:00:00');
        $c = $this->img(3, '2024-01-01 12:00:00', '52.0', '13.008'); // ~1km east
        $result = ImageLocationInterpolator::interpolate([$a, $b, $c]);
        $this->assertEqualsWithDelta(52.0, (float)$result[1]->lat, 0.0001);
        $this->assertEqualsWithDelta(13.004, (float)$result[1]->lon, 0.0001);
    }

    public function testNoInterpolationIfSpatialConstraintViolated()
    {
        $a = $this->img(1, '2024-01-01 10:00:00', '52.0', '13.0');
        $b = $this->img(2, '2024-01-01 11:00:00');
        $c = $this->img(3, '2024-01-01 12:00:00', '53.0', '14.0'); // >1km
        $result = ImageLocationInterpolator::interpolate([$a, $b, $c]);
        $this->assertNull($result[1]->lat);
        $this->assertNull($result[1]->lon);
    }

    public function testNoInterpolationIfTemporalConstraintViolated()
    {
        $a = $this->img(1, '2024-01-01 10:00:00', '52.0', '13.0');
        $b = $this->img(2, '2024-01-02 11:00:00'); // >6h gap
        $c = $this->img(3, '2024-01-02 12:00:00', '52.1', '13.1');
        $result = ImageLocationInterpolator::interpolate([$a, $b, $c]);
        $this->assertNull($result[1]->lat);
        $this->assertNull($result[1]->lon);
    }

    public function testSinglePrecedingNeighborWithin1h()
    {
        $a = $this->img(1, '2024-01-01 10:00:00', '52.0', '13.0');
        $b = $this->img(2, '2024-01-01 10:30:00');
        $result = ImageLocationInterpolator::interpolate([$a, $b]);
        $this->assertEquals('52.0', $result[1]->lat);
        $this->assertEquals('13.0', $result[1]->lon);
    }

    public function testSinglePrecedingNeighborMoreThan1h()
    {
        $a = $this->img(1, '2024-01-01 10:00:00', '52.0', '13.0');
        $b = $this->img(2, '2024-01-01 12:00:00');
        $result = ImageLocationInterpolator::interpolate([$a, $b]);
        $this->assertNull($result[1]->lat);
        $this->assertNull($result[1]->lon);
    }

    public function testSingleFollowingNeighborWithin1h()
    {
        $a = $this->img(1, '2024-01-01 10:00:00');
        $b = $this->img(2, '2024-01-01 10:30:00', '52.0', '13.0');
        $result = ImageLocationInterpolator::interpolate([$a, $b]);
        $this->assertEquals('52.0', $result[0]->lat);
        $this->assertEquals('13.0', $result[0]->lon);
    }

    public function testSingleFollowingNeighborMoreThan1h()
    {
        $a = $this->img(1, '2024-01-01 10:00:00');
        $b = $this->img(2, '2024-01-01 12:00:00', '52.0', '13.0');
        $result = ImageLocationInterpolator::interpolate([$a, $b]);
        $this->assertNull($result[0]->lat);
        $this->assertNull($result[0]->lon);
    }

    public function testNoNeighborsWithLocation()
    {
        $a = $this->img(1, '2024-01-01 10:00:00');
        $b = $this->img(2, '2024-01-01 11:00:00');
        $result = ImageLocationInterpolator::interpolate([$a, $b]);
        $this->assertNull($result[0]->lat);
        $this->assertNull($result[0]->lon);
        $this->assertNull($result[1]->lat);
        $this->assertNull($result[1]->lon);
    }

    public function testAllImagesHaveLocation()
    {
        $a = $this->img(1, '2024-01-01 10:00:00', '52.0', '13.0');
        $b = $this->img(2, '2024-01-01 11:00:00', '52.1', '13.1');
        $result = ImageLocationInterpolator::interpolate([$a, $b]);
        $this->assertEquals('52.0', $result[0]->lat);
        $this->assertEquals('52.1', $result[1]->lat);
    }
}
