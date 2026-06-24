<?php
namespace OCA\Journeys\Model;

/**
 * A single daily journal entry within a journal: a date, optional title, journal
 * text, and a denormalized location cache derived from the entry's photos.
 * The curated photos live in EntryPhoto rows.
 */
class JournalEntry {
    /** @var EntryPhoto[] */
    public array $photos = [];

    public function __construct(
        public int $id,
        public int $journalId,
        public string $entryDate,   // 'Y-m-d'
        public ?string $title = null,
        public ?string $body = null,
        public int $sortOrder = 0,
        public ?float $lat = null,
        public ?float $lon = null,
        public ?string $placeLabel = null,
        public ?string $city = null,
        public ?string $country = null,
        public ?string $countryCode = null,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {}

    public static function fromRow(array $row): self {
        return new self(
            (int)$row['id'],
            (int)$row['journal_id'],
            (string)$row['entry_date'],
            isset($row['title']) ? (string)$row['title'] : null,
            isset($row['body']) ? (string)$row['body'] : null,
            isset($row['sort_order']) ? (int)$row['sort_order'] : 0,
            isset($row['lat']) && $row['lat'] !== null ? (float)$row['lat'] : null,
            isset($row['lon']) && $row['lon'] !== null ? (float)$row['lon'] : null,
            $row['place_label'] ?? null,
            $row['city'] ?? null,
            $row['country'] ?? null,
            $row['country_code'] ?? null,
            $row['created_at'] ?? null,
            $row['updated_at'] ?? null,
        );
    }

    public function hasLocation(): bool {
        return $this->countryCode !== null || $this->placeLabel !== null;
    }
}
