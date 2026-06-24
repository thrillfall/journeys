<?php
namespace OCA\Journeys\Model;

/**
 * A user-authored journal: the top-level container that groups daily journal
 * entries and is the unit published to a public share page.
 */
class Journal {
    /** @var JournalEntry[] */
    public array $entries = [];

    public function __construct(
        public int $id,
        public string $userId,
        public string $title,
        public ?string $description = null,
        public ?int $coverFileid = null,
        public ?string $startDate = null,   // 'Y-m-d'
        public ?string $endDate = null,     // 'Y-m-d'
        public ?string $publicToken = null,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {}

    public static function fromRow(array $row): self {
        return new self(
            (int)$row['id'],
            (string)$row['user_id'],
            (string)$row['title'],
            isset($row['description']) ? (string)$row['description'] : null,
            isset($row['cover_fileid']) && $row['cover_fileid'] !== null ? (int)$row['cover_fileid'] : null,
            $row['start_date'] ?? null,
            $row['end_date'] ?? null,
            $row['public_token'] ?? null,
            $row['created_at'] ?? null,
            $row['updated_at'] ?? null,
        );
    }

    public function isPublic(): bool {
        return $this->publicToken !== null && $this->publicToken !== '';
    }

    /**
     * Normalize a user-supplied journal title: trim, collapse internal whitespace,
     * clamp length, and fall back to a default when empty. Pure — unit tested.
     */
    public static function sanitizeTitle(?string $title): string {
        $clean = trim((string)($title ?? ''));
        // Collapse any run of whitespace (incl. newlines/tabs) to a single space.
        $clean = (string)preg_replace('/\s+/u', ' ', $clean);
        if ($clean === '') {
            return 'Untitled journal';
        }
        if (mb_strlen($clean) > 255) {
            $clean = mb_substr($clean, 0, 255);
        }
        return $clean;
    }
}
