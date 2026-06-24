<?php
namespace OCA\Journeys\Model;

/**
 * A photo curated into a journal entry, referenced by fileid (mount-agnostic,
 * same convention as the rest of the app) with an explicit display order and
 * optional caption.
 */
class EntryPhoto {
    public function __construct(
        public int $id,
        public int $entryId,
        public int $fileid,
        public int $sortOrder = 0,
        public ?string $caption = null,
        public ?string $ownerUid = null,
    ) {}

    public static function fromRow(array $row): self {
        return new self(
            (int)$row['id'],
            (int)$row['entry_id'],
            (int)$row['fileid'],
            isset($row['sort_order']) ? (int)$row['sort_order'] : 0,
            isset($row['caption']) ? (string)$row['caption'] : null,
            isset($row['owner_uid']) && $row['owner_uid'] !== null ? (string)$row['owner_uid'] : null,
        );
    }

    /**
     * Normalize a user-supplied photo selection into the rows to persist for an
     * entry. Accepts either bare fileids (int) or maps of
     * ['fileid' => int, 'caption' => ?string]. Drops non-positive/duplicate
     * fileids (first occurrence wins, preserving order) and assigns a dense,
     * zero-based sort_order. Pure — unit tested.
     *
     * @param array<int|array<string,mixed>> $items
     * @return array<int,array{fileid:int,caption:?string,sort_order:int}>
     */
    public static function normalizeSelection(array $items): array {
        $out = [];
        $seen = [];
        foreach ($items as $item) {
            $fileid = 0;
            $caption = null;
            if (is_array($item)) {
                $fileid = isset($item['fileid']) ? (int)$item['fileid'] : 0;
                if (isset($item['caption'])) {
                    $c = trim((string)$item['caption']);
                    $caption = $c === '' ? null : $c;
                }
            } else {
                $fileid = (int)$item;
            }
            if ($fileid <= 0 || isset($seen[$fileid])) {
                continue;
            }
            $seen[$fileid] = true;
            $out[] = [
                'fileid' => $fileid,
                'caption' => $caption,
                'sort_order' => count($out),
            ];
        }
        return $out;
    }
}
