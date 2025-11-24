<?php
namespace OCA\Journeys\Model;

use DateTimeImmutable;

class ClusterVideoSelection {
    /**
     * @param Image[] $selectedImages
     */
    public function __construct(
        public array $selectedImages,
        public int $clusterIndex,
        public DateTimeImmutable $clusterStart,
        public DateTimeImmutable $clusterEnd,
        public ?string $clusterLocation,
        public string $clusterName,
        public bool $boostFaces,
        public int $selectedCount,
        public int $facesSelected,
    ) {}
}
