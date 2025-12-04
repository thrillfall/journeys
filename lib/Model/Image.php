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
        public ?int $h = null,  // orientation-corrected height from Memories (optional)
        public ?bool $hasFaces = null,
        public ?string $cameraMake = null,
        public ?string $cameraModel = null,
    ) {}

    public static function isLikelyScreenshot(?int $w, ?int $h, ?string $cameraMake, ?string $cameraModel, string $path): bool {
        $pathLower = strtolower($path);
        if (self::hasCameraMetadata($cameraMake, $cameraModel)) {
            return false;
        }

        if (str_contains($pathLower, 'screenshot') || str_contains($pathLower, 'screen-shot') || str_contains($pathLower, 'screen_shot')) {
            return true;
        }

        if (str_contains($pathLower, '/screenshots') || str_contains($pathLower, '/screen/')) {
            return true;
        }

        $missingExif = $w === null || $h === null || $w <= 0 || $h <= 0;
        if ($missingExif) {
            return true;
        }

        $extension = strtolower(pathinfo($pathLower, PATHINFO_EXTENSION));
        if ($extension !== '' && in_array($extension, ['png', 'webp', 'gif', 'bmp'], true)) {
            return true;
        }

        $ratio = max($w, $h) / max(1, min($w, $h));
        if ($ratio >= 1.8 && $ratio <= 2.4) {
            return true;
        }

        return false;
    }

    public function isUsable(): bool {
        return !self::isLikelyScreenshot($this->w, $this->h, $this->cameraMake, $this->cameraModel, $this->path);
    }

    private static function hasCameraMetadata(?string $make, ?string $model): bool {
        return trim($make ?? '') !== '' || trim($model ?? '') !== '';
    }
}
