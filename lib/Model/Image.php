<?php
namespace OCA\Journeys\Model;

class Image {
    public function __construct(
        public int $fileid,
        public string $path,
        public string $datetaken,
        public ?string $lat,
        public ?string $lon,
        public ?int $w = null,  // orientation-corrected width from Memories (optional)
        public ?int $h = null   // orientation-corrected height from Memories (optional)
    ) {}
}
