<?php
namespace OCA\Journeys\Service;

use OCA\Journeys\Exception\JournalNotFoundException;
use OCA\Journeys\Model\EntryPhoto;
use OCA\Journeys\Model\Journal;
use OCA\Journeys\Model\JournalEntry;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * CRUD for the travel-diary data model (journals, daily entries, curated photos).
 * All mutations are scoped to the acting user; ownership is enforced on every
 * read and write, and an unauthorized id is reported as JournalNotFoundException
 * (indistinguishable from a genuinely missing id).
 */
class JournalService {

    public function __construct(
        private IDBConnection $db,
        private IUserManager $userManager,
        private IGroupManager $groupManager,
        private IRootFolder $rootFolder,
    ) {}

    // -- journals ----------------------------------------------------------------

    public function createJournal(string $userId, ?string $title, ?string $description = null): int {
        $now = $this->now();
        $qb = $this->db->getQueryBuilder();
        $qb->insert('journeys_journals')->values([
            'user_id' => $qb->createNamedParameter($userId),
            'title' => $qb->createNamedParameter(Journal::sanitizeTitle($title)),
            'description' => $qb->createNamedParameter($this->nullableText($description)),
            'created_at' => $qb->createNamedParameter($now),
            'updated_at' => $qb->createNamedParameter($now),
        ]);
        $qb->executeStatement();
        return (int)$qb->getLastInsertId();
    }

    /** @return Journal[] owned + shared-with-me, newest first */
    public function listJournals(string $userId): array {
        $ids = $this->memberJournalIds($userId);
        $qb = $this->db->getQueryBuilder();
        $or = $qb->expr()->orX($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        if ($ids) {
            $or->add($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
        }
        $qb->select('*')->from('journeys_journals')
            ->where($or)
            ->orderBy('start_date', 'DESC')
            ->addOrderBy('id', 'DESC');
        $rows = $qb->executeQuery()->fetchAll();
        return array_map(static fn(array $r) => Journal::fromRow($r), $rows);
    }

    /** Access-aware read: returns the journal if the user owns it or is a member. */
    public function getJournal(string $userId, int $journalId): ?Journal {
        $journal = $this->loadJournal($journalId);
        if ($journal === null || !$this->canAccess($userId, $journal)) {
            return null;
        }
        return $journal;
    }

    /** Unscoped load by id. */
    private function loadJournal(int $journalId): ?Journal {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from('journeys_journals')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($journalId, IQueryBuilder::PARAM_INT)));
        $row = $qb->executeQuery()->fetch();
        return $row ? Journal::fromRow($row) : null;
    }

    public function isOwner(string $userId, Journal $journal): bool {
        return $journal->userId === $userId;
    }

    /** Owner, direct user-member, or member via any of the user's groups. */
    public function canAccess(string $userId, Journal $journal): bool {
        if ($journal->userId === $userId) {
            return true;
        }
        $qb = $this->db->getQueryBuilder();
        $or = $qb->expr()->orX(
            $qb->expr()->andX(
                $qb->expr()->eq('principal_type', $qb->createNamedParameter('user')),
                $qb->expr()->eq('principal_id', $qb->createNamedParameter($userId)),
            )
        );
        $groups = $this->userGroupIds($userId);
        if ($groups) {
            $or->add($qb->expr()->andX(
                $qb->expr()->eq('principal_type', $qb->createNamedParameter('group')),
                $qb->expr()->in('principal_id', $qb->createNamedParameter($groups, IQueryBuilder::PARAM_STR_ARRAY)),
            ));
        }
        $qb->select('id')->from('journeys_journal_members')
            ->where($qb->expr()->eq('journal_id', $qb->createNamedParameter($journal->id, IQueryBuilder::PARAM_INT)))
            ->andWhere($or)
            ->setMaxResults(1);
        return $qb->executeQuery()->fetch() !== false;
    }

    /** Journal with its entries (each with photos) populated, or null. */
    public function getJournalWithEntries(string $userId, int $journalId): ?Journal {
        $journal = $this->getJournal($userId, $journalId);
        if ($journal === null) {
            return null;
        }
        $journal->entries = $this->listEntries($journalId);
        return $journal;
    }

    /**
     * Update mutable journal metadata (full co-edit — any member). Only keys present
     * in $fields are touched. Recognized: title, description, coverFileid.
     * start/end dates are derived from entries, not set here.
     */
    public function updateJournal(string $userId, int $journalId, array $fields): bool {
        $this->requireAccess($userId, $journalId);
        $qb = $this->db->getQueryBuilder();
        $qb->update('journeys_journals')
            ->set('updated_at', $qb->createNamedParameter($this->now()));
        if (array_key_exists('title', $fields)) {
            $qb->set('title', $qb->createNamedParameter(Journal::sanitizeTitle($fields['title'])));
        }
        if (array_key_exists('description', $fields)) {
            $qb->set('description', $qb->createNamedParameter($this->nullableText($fields['description'])));
        }
        if (array_key_exists('coverFileid', $fields)) {
            $cover = $fields['coverFileid'] !== null ? (int)$fields['coverFileid'] : null;
            $qb->set('cover_fileid', $qb->createNamedParameter($cover, IQueryBuilder::PARAM_INT));
        }
        // No user_id scoping: access is already authorized via requireAccess, and
        // scoping by owner here would silently no-op a collaborator's edit.
        $qb->where($qb->expr()->eq('id', $qb->createNamedParameter($journalId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
        return true;
    }

    public function deleteJournal(string $userId, int $journalId): bool {
        $this->requireOwner($userId, $journalId);
        $this->db->beginTransaction();
        try {
            $entryIds = $this->entryIdsForJournal($journalId);
            $this->deletePhotosForEntries($entryIds);

            $qb = $this->db->getQueryBuilder();
            $qb->delete('journeys_journal_entries')
                ->where($qb->expr()->eq('journal_id', $qb->createNamedParameter($journalId, IQueryBuilder::PARAM_INT)));
            $qb->executeStatement();

            $qb = $this->db->getQueryBuilder();
            $qb->delete('journeys_journal_members')
                ->where($qb->expr()->eq('journal_id', $qb->createNamedParameter($journalId, IQueryBuilder::PARAM_INT)));
            $qb->executeStatement();

            $qb = $this->db->getQueryBuilder();
            $qb->delete('journeys_journals')
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($journalId, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
            $qb->executeStatement();

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return true;
    }

    /**
     * Set or clear a journal's public share token (owner-scoped).
     * @throws JournalNotFoundException
     */
    public function setPublicToken(string $userId, int $journalId, ?string $token): bool {
        $this->requireOwner($userId, $journalId);
        $qb = $this->db->getQueryBuilder();
        $qb->update('journeys_journals')
            ->set('public_token', $qb->createNamedParameter($token))
            ->set('updated_at', $qb->createNamedParameter($this->now()))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($journalId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $qb->executeStatement();
        return true;
    }

    /** Public lookup by share token (no user scoping). Null if not shared/found. */
    public function getJournalByToken(string $token): ?Journal {
        if ($token === '') {
            return null;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from('journeys_journals')
            ->where($qb->expr()->eq('public_token', $qb->createNamedParameter($token)));
        $row = $qb->executeQuery()->fetch();
        return $row ? Journal::fromRow($row) : null;
    }

    /** True if the given fileid is attached to any entry of the journal. */
    public function journalHasPhoto(int $journalId, int $fileid): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('p.id')
            ->from('journeys_entry_photos', 'p')
            ->innerJoin('p', 'journeys_journal_entries', 'e', $qb->expr()->eq('p.entry_id', 'e.id'))
            ->where($qb->expr()->eq('e.journal_id', $qb->createNamedParameter($journalId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('p.fileid', $qb->createNamedParameter($fileid, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        return $qb->executeQuery()->fetch() !== false;
    }

    /** owner_uid of the matching entry_photos row (null → caller falls back to journal owner). */
    public function getPhotoOwnerUid(int $journalId, int $fileid): ?string {
        $qb = $this->db->getQueryBuilder();
        $qb->select('p.owner_uid')
            ->from('journeys_entry_photos', 'p')
            ->innerJoin('p', 'journeys_journal_entries', 'e', $qb->expr()->eq('p.entry_id', 'e.id'))
            ->where($qb->expr()->eq('e.journal_id', $qb->createNamedParameter($journalId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('p.fileid', $qb->createNamedParameter($fileid, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetch();
        return ($row && $row['owner_uid'] !== null) ? (string)$row['owner_uid'] : null;
    }

    // -- members (collaboration) ----------------------------------------------

    /** @return array<int,array{type:string,id:string}> */
    public function listMembers(string $userId, int $journalId): array {
        $this->requireAccess($userId, $journalId);
        $qb = $this->db->getQueryBuilder();
        $qb->select('principal_type', 'principal_id')->from('journeys_journal_members')
            ->where($qb->expr()->eq('journal_id', $qb->createNamedParameter($journalId, IQueryBuilder::PARAM_INT)))
            ->orderBy('principal_type', 'ASC')->addOrderBy('principal_id', 'ASC');
        return array_map(
            static fn(array $r) => ['type' => (string)$r['principal_type'], 'id' => (string)$r['principal_id']],
            $qb->executeQuery()->fetchAll()
        );
    }

    /** Owner-only. Idempotent (delete-then-insert). @throws JournalNotFoundException */
    public function addMember(string $userId, int $journalId, string $type, string $principal): bool {
        $this->requireOwner($userId, $journalId);
        if (!in_array($type, ['user', 'group'], true) || trim($principal) === '') {
            return false;
        }
        $this->removeMember($userId, $journalId, $type, $principal, false);
        $qb = $this->db->getQueryBuilder();
        $qb->insert('journeys_journal_members')->values([
            'journal_id' => $qb->createNamedParameter($journalId, IQueryBuilder::PARAM_INT),
            'principal_type' => $qb->createNamedParameter($type),
            'principal_id' => $qb->createNamedParameter($principal),
            'created_at' => $qb->createNamedParameter($this->now()),
        ]);
        $qb->executeStatement();
        return true;
    }

    /** Owner-only. @throws JournalNotFoundException */
    public function removeMember(string $userId, int $journalId, string $type, string $principal, bool $check = true): bool {
        if ($check) {
            $this->requireOwner($userId, $journalId);
        }
        $qb = $this->db->getQueryBuilder();
        $qb->delete('journeys_journal_members')
            ->where($qb->expr()->eq('journal_id', $qb->createNamedParameter($journalId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('principal_type', $qb->createNamedParameter($type)))
            ->andWhere($qb->expr()->eq('principal_id', $qb->createNamedParameter($principal)));
        $qb->executeStatement();
        return true;
    }

    /**
     * Remove all of a (deleted) user's data: journals they own (cascade), photos
     * they contributed to any journal (owner_uid), and membership rows referencing
     * them. Called from the UserDeletedEvent listener.
     */
    public function purgeUser(string $userId): void {
        // 1) Contributed photos in other people's journals.
        $qb = $this->db->getQueryBuilder();
        $qb->delete('journeys_entry_photos')
            ->where($qb->expr()->eq('owner_uid', $qb->createNamedParameter($userId)));
        $qb->executeStatement();

        // 2) Membership rows naming this user as a principal.
        $qb = $this->db->getQueryBuilder();
        $qb->delete('journeys_journal_members')
            ->where($qb->expr()->eq('principal_type', $qb->createNamedParameter('user')))
            ->andWhere($qb->expr()->eq('principal_id', $qb->createNamedParameter($userId)));
        $qb->executeStatement();

        // 3) Journals they own, with their entries / photos / members.
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from('journeys_journals')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $journalIds = array_map(static fn(array $r) => (int)$r['id'], $qb->executeQuery()->fetchAll());
        foreach ($journalIds as $journalId) {
            $this->deletePhotosForEntries($this->entryIdsForJournal($journalId));
            foreach (['journeys_journal_entries', 'journeys_journal_members'] as $table) {
                $d = $this->db->getQueryBuilder();
                $d->delete($table)->where($d->expr()->eq('journal_id', $d->createNamedParameter($journalId, IQueryBuilder::PARAM_INT)));
                $d->executeStatement();
            }
            $d = $this->db->getQueryBuilder();
            $d->delete('journeys_journals')->where($d->expr()->eq('id', $d->createNamedParameter($journalId, IQueryBuilder::PARAM_INT)));
            $d->executeStatement();
        }
    }

    /** @return int[] journal ids the user is a member of (direct or via group) */
    private function memberJournalIds(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $or = $qb->expr()->orX(
            $qb->expr()->andX(
                $qb->expr()->eq('principal_type', $qb->createNamedParameter('user')),
                $qb->expr()->eq('principal_id', $qb->createNamedParameter($userId)),
            )
        );
        $groups = $this->userGroupIds($userId);
        if ($groups) {
            $or->add($qb->expr()->andX(
                $qb->expr()->eq('principal_type', $qb->createNamedParameter('group')),
                $qb->expr()->in('principal_id', $qb->createNamedParameter($groups, IQueryBuilder::PARAM_STR_ARRAY)),
            ));
        }
        $qb->selectDistinct('journal_id')->from('journeys_journal_members')->where($or);
        return array_map(static fn(array $r) => (int)$r['journal_id'], $qb->executeQuery()->fetchAll());
    }

    /** @return string[] group ids the user belongs to */
    private function userGroupIds(string $userId): array {
        $user = $this->userManager->get($userId);
        if ($user === null) {
            return [];
        }
        return $this->groupManager->getUserGroupIds($user);
    }

    // -- entries --------------------------------------------------------------

    public function createEntry(string $userId, int $journalId, string $entryDate, ?string $title = null, ?string $body = null, int $sortOrder = 0): int {
        $this->requireAccess($userId, $journalId);
        $now = $this->now();
        $qb = $this->db->getQueryBuilder();
        $qb->insert('journeys_journal_entries')->values([
            'journal_id' => $qb->createNamedParameter($journalId, IQueryBuilder::PARAM_INT),
            'entry_date' => $qb->createNamedParameter($entryDate),
            'title' => $qb->createNamedParameter($this->nullableText($title)),
            'body' => $qb->createNamedParameter($this->nullableText($body)),
            'sort_order' => $qb->createNamedParameter($sortOrder, IQueryBuilder::PARAM_INT),
            'author_uid' => $qb->createNamedParameter($userId),
            'created_at' => $qb->createNamedParameter($now),
            'updated_at' => $qb->createNamedParameter($now),
        ]);
        $qb->executeStatement();
        $id = (int)$qb->getLastInsertId();
        $this->recomputeJournalDates($journalId);
        return $id;
    }

    /**
     * Update mutable entry fields. Recognized: entryDate, title, body,
     * sortOrder, and the location cache (lat, lon, placeLabel, city, country,
     * countryCode). Only keys present in $fields are touched.
     */
    public function updateEntry(string $userId, int $entryId, array $fields): bool {
        $journalId = $this->requireEntryJournalId($userId, $entryId);
        $qb = $this->db->getQueryBuilder();
        $qb->update('journeys_journal_entries')
            ->set('updated_at', $qb->createNamedParameter($this->now()));
        if (array_key_exists('entryDate', $fields)) {
            $qb->set('entry_date', $qb->createNamedParameter($fields['entryDate']));
        }
        if (array_key_exists('title', $fields)) {
            $qb->set('title', $qb->createNamedParameter($this->nullableText($fields['title'])));
        }
        if (array_key_exists('body', $fields)) {
            $qb->set('body', $qb->createNamedParameter($this->nullableText($fields['body'])));
        }
        if (array_key_exists('sortOrder', $fields)) {
            $qb->set('sort_order', $qb->createNamedParameter((int)$fields['sortOrder'], IQueryBuilder::PARAM_INT));
        }
        $locationMap = [
            'lat' => 'lat', 'lon' => 'lon', 'placeLabel' => 'place_label',
            'city' => 'city', 'country' => 'country', 'countryCode' => 'country_code',
        ];
        foreach ($locationMap as $key => $column) {
            if (array_key_exists($key, $fields)) {
                $qb->set($column, $qb->createNamedParameter($fields[$key]));
            }
        }
        $qb->where($qb->expr()->eq('id', $qb->createNamedParameter($entryId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
        if (array_key_exists('entryDate', $fields)) {
            $this->recomputeJournalDates($journalId);
        }
        return true;
    }

    public function deleteEntry(string $userId, int $entryId): bool {
        $journalId = $this->requireEntryJournalId($userId, $entryId);
        $this->db->beginTransaction();
        try {
            $this->deletePhotosForEntries([$entryId]);
            $qb = $this->db->getQueryBuilder();
            $qb->delete('journeys_journal_entries')
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($entryId, IQueryBuilder::PARAM_INT)));
            $qb->executeStatement();
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        $this->recomputeJournalDates($journalId);
        return true;
    }

    /** Derive journal start/end from its entries' dates (or null when empty). */
    private function recomputeJournalDates(int $journalId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->selectAlias($qb->func()->min('entry_date'), 'mn')
            ->selectAlias($qb->func()->max('entry_date'), 'mx')
            ->from('journeys_journal_entries')
            ->where($qb->expr()->eq('journal_id', $qb->createNamedParameter($journalId, IQueryBuilder::PARAM_INT)));
        $row = $qb->executeQuery()->fetch();
        $u = $this->db->getQueryBuilder();
        $u->update('journeys_journals')
            ->set('start_date', $u->createNamedParameter($row['mn'] ?? null))
            ->set('end_date', $u->createNamedParameter($row['mx'] ?? null))
            ->set('updated_at', $u->createNamedParameter($this->now()))
            ->where($u->expr()->eq('id', $u->createNamedParameter($journalId, IQueryBuilder::PARAM_INT)));
        $u->executeStatement();
    }

    /** A single entry (with photos) within a journal, or null. */
    public function getEntry(int $journalId, int $entryId): ?JournalEntry {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from('journeys_journal_entries')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($entryId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('journal_id', $qb->createNamedParameter($journalId, IQueryBuilder::PARAM_INT)));
        $row = $qb->executeQuery()->fetch();
        if (!$row) {
            return null;
        }
        $entry = JournalEntry::fromRow($row);
        $byId = [$entry->id => $entry];
        $this->attachPhotos($byId);
        return $entry;
    }

    /** @return JournalEntry[] ordered by date then sort_order, each with photos */
    public function listEntries(int $journalId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from('journeys_journal_entries')
            ->where($qb->expr()->eq('journal_id', $qb->createNamedParameter($journalId, IQueryBuilder::PARAM_INT)))
            ->orderBy('entry_date', 'ASC')
            ->addOrderBy('sort_order', 'ASC')
            ->addOrderBy('id', 'ASC');
        $rows = $qb->executeQuery()->fetchAll();
        if (!$rows) {
            return [];
        }
        $entries = [];
        $byId = [];
        foreach ($rows as $row) {
            $entry = JournalEntry::fromRow($row);
            $entries[] = $entry;
            $byId[$entry->id] = $entry;
        }
        $this->attachPhotos($byId);
        return $entries;
    }

    // -- entry photos ---------------------------------------------------------

    /**
     * Replace an entry's photo selection with the normalized $items
     * (bare fileids or ['fileid'=>..,'caption'=>..]). Returns the stored rows.
     *
     * @param array<int|array<string,mixed>> $items
     * @return EntryPhoto[]
     */
    public function setEntryPhotos(string $userId, int $entryId, array $items): array {
        $this->requireEntryJournalId($userId, $entryId);
        $normalized = EntryPhoto::normalizeSelection($items);

        // Preserve the original owner of photos already on the entry (so a
        // collaborator's edit doesn't re-attribute others' photos), and only
        // allow NEWLY-added fileids that belong to the acting user (you can
        // remove anyone's photo, but only add your own).
        $existingOwners = [];
        foreach ($this->getEntryPhotos($entryId) as $p) {
            $existingOwners[$p->fileid] = $p->ownerUid ?? $userId;
        }

        $this->db->beginTransaction();
        try {
            $this->deletePhotosForEntries([$entryId]);
            $order = 0;
            foreach ($normalized as $photo) {
                $fid = $photo['fileid'];
                if (array_key_exists($fid, $existingOwners)) {
                    // kept/reordered — re-derive from real storage (self-heals
                    // stale owner_uid), falling back to the stored value.
                    $owner = $this->fileHomeOwner($fid) ?? ($existingOwners[$fid] ?? $userId);
                } else {
                    if (!$this->userOwnsFile($userId, $fid)) {
                        continue; // can't add a file you don't own
                    }
                    $owner = $this->fileHomeOwner($fid) ?? $userId;
                }
                $qb = $this->db->getQueryBuilder();
                $qb->insert('journeys_entry_photos')->values([
                    'entry_id' => $qb->createNamedParameter($entryId, IQueryBuilder::PARAM_INT),
                    'fileid' => $qb->createNamedParameter($fid, IQueryBuilder::PARAM_INT),
                    'owner_uid' => $qb->createNamedParameter($owner),
                    'sort_order' => $qb->createNamedParameter($order++, IQueryBuilder::PARAM_INT),
                    'caption' => $qb->createNamedParameter($photo['caption']),
                ]);
                $qb->executeStatement();
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $this->getEntryPhotos($entryId);
    }

    /** The home-storage owner uid of a fileid (home::<uid>), or null. */
    private function fileHomeOwner(int $fileid): ?string {
        $qb = $this->db->getQueryBuilder();
        $qb->select('s.id')
            ->from('filecache', 'f')
            ->innerJoin('f', 'storages', 's', $qb->expr()->eq('f.storage', 's.numeric_id'))
            ->where($qb->expr()->eq('f.fileid', $qb->createNamedParameter($fileid, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetch();
        $sid = $row['id'] ?? '';
        return str_starts_with($sid, 'home::') ? substr($sid, 6) : null;
    }

    /** True if the fileid is reachable in the user's own files. */
    private function userOwnsFile(string $userId, int $fileid): bool {
        try {
            return $this->rootFolder->getUserFolder($userId)->getById($fileid) !== [];
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** @return EntryPhoto[] ordered by sort_order */
    public function getEntryPhotos(int $entryId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from('journeys_entry_photos')
            ->where($qb->expr()->eq('entry_id', $qb->createNamedParameter($entryId, IQueryBuilder::PARAM_INT)))
            ->orderBy('sort_order', 'ASC')
            ->addOrderBy('id', 'ASC');
        $rows = $qb->executeQuery()->fetchAll();
        return array_map(static fn(array $r) => EntryPhoto::fromRow($r), $rows);
    }

    // -- ownership helpers ----------------------------------------------------

    /** Require the user can access (own or be a member of) the journal. @throws JournalNotFoundException */
    private function requireAccess(string $userId, int $journalId): Journal {
        $journal = $this->loadJournal($journalId);
        if ($journal === null || !$this->canAccess($userId, $journal)) {
            throw new JournalNotFoundException("Journal {$journalId} not found");
        }
        return $journal;
    }

    /** Require the user owns the journal (membership management, publish, delete). @throws JournalNotFoundException */
    private function requireOwner(string $userId, int $journalId): Journal {
        $journal = $this->loadJournal($journalId);
        if ($journal === null || !$this->isOwner($userId, $journal)) {
            throw new JournalNotFoundException("Journal {$journalId} not found");
        }
        return $journal;
    }

    /**
     * Verify the entry exists and its journal is accessible by the user (full
     * co-edit); return journal id.
     * @throws JournalNotFoundException
     */
    private function requireEntryJournalId(string $userId, int $entryId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select('journal_id')
            ->from('journeys_journal_entries')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($entryId, IQueryBuilder::PARAM_INT)));
        $row = $qb->executeQuery()->fetch();
        if (!$row) {
            throw new JournalNotFoundException("Entry {$entryId} not found");
        }
        $journalId = (int)$row['journal_id'];
        $this->requireAccess($userId, $journalId);
        return $journalId;
    }

    // -- internals ------------------------------------------------------------

    /** @param JournalEntry[] $byId keyed by entry id */
    private function attachPhotos(array $byId): void {
        $entryIds = array_keys($byId);
        if (!$entryIds) {
            return;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from('journeys_entry_photos')
            ->where($qb->expr()->in('entry_id', $qb->createNamedParameter($entryIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->orderBy('sort_order', 'ASC')
            ->addOrderBy('id', 'ASC');
        $rows = $qb->executeQuery()->fetchAll();
        foreach ($rows as $row) {
            $photo = EntryPhoto::fromRow($row);
            if (isset($byId[$photo->entryId])) {
                $byId[$photo->entryId]->photos[] = $photo;
            }
        }
    }

    /** @return int[] entry ids for a journal */
    private function entryIdsForJournal(int $journalId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from('journeys_journal_entries')
            ->where($qb->expr()->eq('journal_id', $qb->createNamedParameter($journalId, IQueryBuilder::PARAM_INT)));
        $rows = $qb->executeQuery()->fetchAll();
        return array_map(static fn(array $r) => (int)$r['id'], $rows);
    }

    /** @param int[] $entryIds */
    private function deletePhotosForEntries(array $entryIds): void {
        if (!$entryIds) {
            return;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->delete('journeys_entry_photos')
            ->where($qb->expr()->in('entry_id', $qb->createNamedParameter($entryIds, IQueryBuilder::PARAM_INT_ARRAY)));
        $qb->executeStatement();
    }

    private function now(): string {
        return gmdate('Y-m-d H:i:s');
    }

    private function nullableText(?string $value): ?string {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }
}
