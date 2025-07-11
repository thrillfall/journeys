<?php
namespace OCA\Journeys\Model;

class Image {
    public function __construct(
        public int $fileid,
        public string $path,
        public string $datetaken,
        public ?string $lat,
        public ?string $lon
    ) {}
}
