<?php
namespace OCA\Journeys\Tests\Service;

use OCA\Journeys\Service\ImageFetcher;
use OCA\Journeys\Model\Image;
use PHPUnit\Framework\TestCase;

class ImageFetcherTest extends TestCase {
    public function testFetchImagesForUserReturnsTypedImages() {
        // This test assumes a Nextcloud test DB with known data, or you can mock the DB connection.
        $fetcher = new ImageFetcher();
        $images = $fetcher->fetchImagesForUser('admin'); // Use a test user with known images

        $this->assertIsArray($images);
        foreach ($images as $image) {
            $this->assertInstanceOf(Image::class, $image);
            $this->assertIsInt($image->fileid);
            $this->assertIsString($image->path);
            $this->assertIsString($image->datetaken);
            $this->assertTrue(is_null($image->lat) || is_string($image->lat));
            $this->assertTrue(is_null($image->lon) || is_string($image->lon));
        }
    }
}
