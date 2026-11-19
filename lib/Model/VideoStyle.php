<?php
namespace OCA\Journeys\Model;

/**
 * Optional render behaviour beyond a plain journey video. Per-file entries are
 * keyed by file basename without extension, like the subtitle map.
 */
class VideoStyle {
    /**
     * @param array{0:int,1:int}|null $size output width and height; null lets the renderer derive it
     * @param array<string,string> $headingsByBasename large chapter heading shown when this file comes up
     * @param array<string,string> $transitionsByBasename ffmpeg xfade transition into this file (default fade)
     */
    public function __construct(
        public bool $chronologicalStacks = false,
        public bool $keepOrder = false,
        public ?array $size = null,
        public array $headingsByBasename = [],
        public array $transitionsByBasename = [],
    ) {}
}
