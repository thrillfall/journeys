<?php
namespace OCA\Journeys\Controller;

use OCA\Journeys\Exception\JournalNotFoundException;
use OCA\Journeys\Model\EntryPhoto;
use OCA\Journeys\Model\Journal;
use OCA\Journeys\Model\JournalEntry;
use OCA\Journeys\Service\DiaryPhotoFetcher;
use OCA\Journeys\Service\EntryLocationResolver;
use OCA\Journeys\Service\JournalService;
use OCA\Journeys\Service\JournalStats;
use OCA\Journeys\Service\PhotoPreviewResponder;
use OCA\Journeys\Service\PhotoSpread;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;

/**
 * Authoring API for the travel diary (Increment 1). All routes are
 * non-admin (user-scoped); writes keep CSRF protection (the Vue client sends the
 * requesttoken). Every action is scoped to the logged-in user — JournalService
 * enforces ownership and reports foreign ids as not-found (404).
 */
class DiaryController extends Controller {

    public function __construct(
        $appName,
        IRequest $request,
        private IUserSession $userSession,
        private JournalService $journalService,
        private DiaryPhotoFetcher $photoFetcher,
        private EntryLocationResolver $locationResolver,
        private ISecureRandom $secureRandom,
        private IURLGenerator $urlGenerator,
        private IUserManager $userManager,
        private IGroupManager $groupManager,
        private PhotoPreviewResponder $photoResponder,
        private IConfig $config,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Serve a preview of a photo attached to a journal the user can access,
     * resolved under the photo's owner_uid — so collaborators see each other's
     * photos even though the file lives in another user's storage (which
     * /core/preview would refuse).
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function journalPhoto(int $id, int $fileid) {
        $userId = $this->uid();
        if ($userId === null) {
            return $this->noUser();
        }
        $journal = $this->journalService->getJournal($userId, $id);
        if ($journal === null || !$this->journalService->journalHasPhoto($id, $fileid)) {
            return $this->notFound();
        }
        // Try the recorded owner first, then the journal owner — robust against
        // stale owner_uid (file may actually live in another member's storage).
        $owner = $this->journalService->getPhotoOwnerUid($id, $fileid);
        return $this->photoResponder->serve([$owner, $journal->userId], $fileid, $this->request->getParam('size'));
    }

    // -- journals ----------------------------------------------------------------

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): JSONResponse {
        $userId = $this->uid();
        if ($userId === null) {
            return $this->noUser();
        }
        $journals = $this->journalService->listJournals($userId);
        $ids = array_map(static fn(Journal $j) => $j->id, $journals);
        $rows = $this->journalService->statsRowsForJournals($ids);
        $counts = $this->journalService->photoCountsForJournals($ids);
        $out = [];
        foreach ($journals as $journal) {
            $data = $this->serializeJournal($journal, false, $userId);
            $data['stats'] = JournalStats::compute($rows[$journal->id] ?? [], $counts[$journal->id] ?? 0);
            $out[] = $data;
        }
        return new JSONResponse(['journals' => $out]);
    }

    #[NoAdminRequired]
    public function create(): JSONResponse {
        $userId = $this->uid();
        if ($userId === null) {
            return $this->noUser();
        }
        $id = $this->journalService->createJournal(
            $userId,
            $this->request->getParam('title'),
            $this->request->getParam('description'),
        );
        $journal = $this->journalService->getJournalWithEntries($userId, $id);
        return new JSONResponse(['journal' => $this->serializeJournal($journal, true, $userId)], 201);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function show(int $id): JSONResponse {
        $userId = $this->uid();
        if ($userId === null) {
            return $this->noUser();
        }
        $journal = $this->journalService->getJournalWithEntries($userId, $id);
        if ($journal === null) {
            return $this->notFound();
        }
        return new JSONResponse(['journal' => $this->serializeJournal($journal, true, $userId)]);
    }

    #[NoAdminRequired]
    public function update(int $id): JSONResponse {
        $userId = $this->uid();
        if ($userId === null) {
            return $this->noUser();
        }
        // start/end dates are derived from entries (not user-set), so not picked here.
        $fields = $this->pick(['title', 'description', 'coverFileid']);
        try {
            $this->journalService->updateJournal($userId, $id, $fields);
        } catch (JournalNotFoundException $e) {
            return $this->notFound();
        }
        return new JSONResponse(['journal' => $this->serializeJournal($this->journalService->getJournalWithEntries($userId, $id), true, $userId)]);
    }

    #[NoAdminRequired]
    public function destroy(int $id): JSONResponse {
        $userId = $this->uid();
        if ($userId === null) {
            return $this->noUser();
        }
        try {
            $this->journalService->deleteJournal($userId, $id);
        } catch (JournalNotFoundException $e) {
            return $this->notFound();
        }
        return new JSONResponse(['deleted' => true]);
    }

    /**
     * Generate (or return existing) public share token for a journal. Owner-only.
     */
    #[NoAdminRequired]
    public function share(int $id): JSONResponse {
        $userId = $this->uid();
        if ($userId === null) {
            return $this->noUser();
        }
        $journal = $this->journalService->getJournal($userId, $id);
        // Sharing is owner-only (don't leak/let members mint a public token).
        if ($journal === null || $journal->userId !== $userId) {
            return $this->notFound();
        }
        $token = $journal->publicToken;
        if (!$token) {
            $token = $this->secureRandom->generate(15, ISecureRandom::CHAR_HUMAN_READABLE);
            $this->journalService->setPublicToken($userId, $id, $token);
        }
        return new JSONResponse(['token' => $token, 'url' => $this->publicUrl($token)]);
    }

    /**
     * Revoke a journal's public share. Owner-only.
     */
    #[NoAdminRequired]
    public function unshare(int $id): JSONResponse {
        $userId = $this->uid();
        if ($userId === null) {
            return $this->noUser();
        }
        try {
            $this->journalService->setPublicToken($userId, $id, null);
        } catch (JournalNotFoundException $e) {
            return $this->notFound();
        }
        return new JSONResponse(['unshared' => true]);
    }

    /** Mark the journal finished, or reopen it. Owner-only. */
    #[NoAdminRequired]
    public function setCompleted(int $id): JSONResponse {
        $userId = $this->uid();
        if ($userId === null) {
            return $this->noUser();
        }
        $completed = filter_var($this->request->getParam('completed', true), FILTER_VALIDATE_BOOLEAN);
        try {
            $this->journalService->setCompleted($userId, $id, $completed);
        } catch (JournalNotFoundException $e) {
            return $this->notFound();
        }
        return new JSONResponse(['journal' => $this->serializeJournal($this->journalService->getJournalWithEntries($userId, $id), true, $userId)]);
    }

    // -- collaboration --------------------------------------------------------

    /**
     * Autocomplete users + groups for the share picker (native sharee search).
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function sharees(): JSONResponse {
        $userId = $this->uid();
        if ($userId === null) {
            return $this->noUser();
        }
        $search = trim((string)$this->request->getParam('search', ''));
        $out = [];
        if ($search === '' || $this->config->getAppValue('core', 'shareapi_enabled', 'yes') !== 'yes') {
            return new JSONResponse(['sharees' => $out]);
        }

        // Honor core's share-dialog restrictions for parity / privacy.
        $enumerate = $this->config->getAppValue('core', 'shareapi_allow_share_dialog_user_enumeration', 'yes') === 'yes';
        $onlyGroupMembers = $this->config->getAppValue('core', 'shareapi_only_share_with_group_members', 'no') === 'yes';
        $allowGroups = $this->config->getAppValue('core', 'shareapi_allow_group_sharing', 'yes') === 'yes';
        $me = $this->userSession->getUser();
        $myGroups = $me ? $this->groupManager->getUserGroupIds($me) : [];

        foreach ($this->userManager->search($search, 30) as $u) {
            if ($u->getUID() === $userId) {
                continue;
            }
            // Enumeration off → only exact uid / display-name matches.
            if (!$enumerate
                && strcasecmp($u->getUID(), $search) !== 0
                && strcasecmp($u->getDisplayName(), $search) !== 0) {
                continue;
            }
            // Restricted to group members → must share a group with the actor.
            if ($onlyGroupMembers && !array_intersect($myGroups, $this->groupManager->getUserGroupIds($u))) {
                continue;
            }
            $out[] = ['type' => 'user', 'id' => $u->getUID(), 'label' => $u->getDisplayName()];
            if (count($out) >= 20) {
                break;
            }
        }

        if ($allowGroups) {
            foreach ($this->groupManager->search($search, 20) as $g) {
                if ($onlyGroupMembers && !in_array($g->getGID(), $myGroups, true)) {
                    continue;
                }
                $out[] = ['type' => 'group', 'id' => $g->getGID(), 'label' => $g->getDisplayName() . ' (group)'];
            }
        }
        return new JSONResponse(['sharees' => $out]);
    }

    /**
     * Let this journal's other members pick from the calling user's own photo
     * library, for the journal's date range. Self-service — a member sets only
     * their own consent, never someone else's.
     */
    #[NoAdminRequired]
    public function setLibraryConsent(int $id): JSONResponse {
        $userId = $this->uid();
        if ($userId === null) {
            return $this->noUser();
        }
        $shared = filter_var($this->request->getParam('shared', false), FILTER_VALIDATE_BOOLEAN);
        try {
            $this->journalService->setLibraryConsent($userId, $id, $shared);
        } catch (JournalNotFoundException $e) {
            return $this->notFound();
        }
        return new JSONResponse([
            'myLibraryShared' => $shared,
            'libraryContributors' => $this->contributors($userId, $id),
        ]);
    }

    /**
     * Preview of a photo that is NOT (yet) attached to the journal, offered by a
     * member who shared their library. Guarded by consent + the journal's date
     * window; `journalPhoto` stays the endpoint for attached photos.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function libraryPhoto(int $id, int $fileid) {
        $userId = $this->uid();
        if ($userId === null) {
            return $this->noUser();
        }
        $journal = $this->journalService->getJournal($userId, $id);
        if ($journal === null || !$journal->startDate || !$journal->endDate) {
            return $this->notFound();
        }
        foreach ($this->journalService->libraryOwnersFor($userId, $id) as $owner) {
            if ($this->photoFetcher->isImageInWindow($fileid, $owner, $journal->startDate, $journal->endDate)) {
                return $this->photoResponder->serve([$owner], $fileid, 'thumb');
            }
        }
        return $this->notFound();
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function members(int $id): JSONResponse {
        $userId = $this->uid();
        if ($userId === null) {
            return $this->noUser();
        }
        try {
            return new JSONResponse(['members' => $this->journalService->listMembers($userId, $id)]);
        } catch (JournalNotFoundException $e) {
            return $this->notFound();
        }
    }

    #[NoAdminRequired]
    public function addMember(int $id): JSONResponse {
        $userId = $this->uid();
        if ($userId === null) {
            return $this->noUser();
        }
        // NB: 'id' is the journal's URL route param — read the principal from a
        // distinct body key to avoid the collision.
        $type = (string)$this->request->getParam('type', '');
        $principal = (string)$this->request->getParam('principal', '');
        $exists = ($type === 'user' && $this->userManager->userExists($principal))
            || ($type === 'group' && $this->groupManager->groupExists($principal));
        if (!$exists) {
            return new JSONResponse(['error' => 'unknown user or group'], 400);
        }
        try {
            $okAdd = $this->journalService->addMember($userId, $id, $type, $principal);
        } catch (JournalNotFoundException $e) {
            return $this->notFound();
        }
        if (!$okAdd) {
            return new JSONResponse(['error' => 'invalid member'], 400);
        }
        return new JSONResponse(['members' => $this->journalService->listMembers($userId, $id)]);
    }

    #[NoAdminRequired]
    public function removeMember(int $id, string $type, string $principal): JSONResponse {
        $userId = $this->uid();
        if ($userId === null) {
            return $this->noUser();
        }
        try {
            $this->journalService->removeMember($userId, $id, $type, $principal);
            return new JSONResponse(['members' => $this->journalService->listMembers($userId, $id)]);
        } catch (JournalNotFoundException $e) {
            return $this->notFound();
        }
    }

    // -- entries --------------------------------------------------------------

    /**
     * Create a daily entry and auto-seed it with that day's home-storage photos,
     * then resolve the entry location from those photos.
     */
    #[NoAdminRequired]
    public function createEntry(int $id): JSONResponse {
        $userId = $this->uid();
        if ($userId === null) {
            return $this->noUser();
        }
        $date = (string)$this->request->getParam('date', '');
        if ($date === '') {
            return new JSONResponse(['error' => 'date is required'], 400);
        }
        try {
            $entryId = $this->journalService->createEntry(
                $userId,
                $id,
                $date,
                $this->request->getParam('title'),
                $this->request->getParam('body'),
            );
        } catch (JournalNotFoundException $e) {
            return $this->notFound();
        }

        // Auto-seed a lean, time-spread subset (≤20) of that day's photos; the
        // user curates the rest via the photo picker. Then resolve location.
        $dayPhotos = $this->photoFetcher->fetchForDay($userId, $date);
        $seed = PhotoSpread::pick($dayPhotos, 20);
        $fileIds = array_map(static fn(array $p) => $p['fileid'], $seed);
        if ($fileIds) {
            $this->journalService->setEntryPhotos($userId, $entryId, $fileIds);
        }
        $this->applyLocation($userId, $entryId, $fileIds);

        return new JSONResponse(['entry' => $this->serializeEntry($this->loadEntry($userId, $id, $entryId))], 201);
    }

    #[NoAdminRequired]
    public function updateEntry(int $entryId): JSONResponse {
        $userId = $this->uid();
        if ($userId === null) {
            return $this->noUser();
        }
        $fields = $this->pick(['entryDate', 'title', 'body', 'sortOrder']);
        try {
            $this->journalService->updateEntry($userId, $entryId, $fields);
        } catch (JournalNotFoundException $e) {
            return $this->notFound();
        }
        return new JSONResponse(['ok' => true]);
    }

    #[NoAdminRequired]
    public function destroyEntry(int $entryId): JSONResponse {
        $userId = $this->uid();
        if ($userId === null) {
            return $this->noUser();
        }
        try {
            $this->journalService->deleteEntry($userId, $entryId);
        } catch (JournalNotFoundException $e) {
            return $this->notFound();
        }
        return new JSONResponse(['deleted' => true]);
    }

    /**
     * Replace an entry's photo selection (bare fileids or {fileid,caption}),
     * then re-resolve the entry location.
     */
    #[NoAdminRequired]
    public function setEntryPhotos(int $entryId): JSONResponse {
        $userId = $this->uid();
        if ($userId === null) {
            return $this->noUser();
        }
        $photos = $this->request->getParam('photos', []);
        if (!is_array($photos)) {
            return new JSONResponse(['error' => 'photos must be an array'], 400);
        }
        try {
            $stored = $this->journalService->setEntryPhotos($userId, $entryId, $photos);
        } catch (JournalNotFoundException $e) {
            return $this->notFound();
        }
        $fileIds = array_map(static fn(EntryPhoto $p) => $p->fileid, $stored);
        $location = $this->applyLocation($userId, $entryId, $fileIds);
        return new JSONResponse([
            'photos' => array_map([$this, 'serializePhoto'], $stored),
            'location' => $location,
        ]);
    }

    // -- photo pickers --------------------------------------------------------

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function dayPhotos(): JSONResponse {
        $userId = $this->uid();
        if ($userId === null) {
            return $this->noUser();
        }
        $date = (string)$this->request->getParam('date', '');
        if ($date === '') {
            return new JSONResponse(['error' => 'date is required'], 400);
        }
        return new JSONResponse(['photos' => $this->photoFetcher->fetchForDay($userId, $date)]);
    }

    /**
     * A day's photos for the journal's picker: the caller's own, plus those of
     * every member who shared their library. Foreign photos are only offered for
     * days inside the journal's date range.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function journalDayPhotos(int $id): JSONResponse {
        $userId = $this->uid();
        if ($userId === null) {
            return $this->noUser();
        }
        $journal = $this->journalService->getJournal($userId, $id);
        if ($journal === null) {
            return $this->notFound();
        }
        $date = (string)$this->request->getParam('date', '');
        if ($date === '') {
            return new JSONResponse(['error' => 'date is required'], 400);
        }

        $owners = [$userId];
        $inWindow = $journal->startDate && $journal->endDate
            && $date >= $journal->startDate && $date <= $journal->endDate;
        if ($inWindow) {
            $owners = $this->journalService->libraryOwnersFor($userId, $id);
        }

        $photos = [];
        foreach ($this->photoFetcher->fetchForDayForUsers($owners, $date) as $photo) {
            $owner = $photo['ownerUid'];
            $photos[] = [
                'fileid' => $photo['fileid'],
                'datetaken' => $photo['datetaken'],
                'ownerUid' => $owner,
                'ownerLabel' => $this->displayName($owner),
                'isMine' => $owner === $userId,
            ];
        }
        return new JSONResponse(['photos' => $photos]);
    }

    /**
     * Photo count per calendar day in a range, so the day picker can mark the
     * days that actually hold pictures. Same sources as journalDayPhotos: the
     * caller's own library, plus consenting members' libraries for the days
     * inside the journal's date range — the counts must not advertise a day the
     * picker would then refuse to show.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function journalPhotoDays(int $id): JSONResponse {
        $userId = $this->uid();
        if ($userId === null) {
            return $this->noUser();
        }
        $journal = $this->journalService->getJournal($userId, $id);
        if ($journal === null) {
            return $this->notFound();
        }
        $from = (string)$this->request->getParam('from', '');
        $to = (string)$this->request->getParam('to', '');
        if ($from === '' || $to === '') {
            return new JSONResponse(['error' => 'from and to are required'], 400);
        }

        $days = $this->photoFetcher->countsByDay($userId, $from, $to);

        if ($journal->startDate && $journal->endDate) {
            $sharedFrom = max($from, $journal->startDate);
            $sharedTo = min($to, $journal->endDate);
            if ($sharedFrom <= $sharedTo) {
                foreach ($this->journalService->libraryOwnersFor($userId, $id) as $owner) {
                    if ($owner === $userId) {
                        continue;
                    }
                    foreach ($this->photoFetcher->countsByDay($owner, $sharedFrom, $sharedTo) as $day => $count) {
                        $days[$day] = ($days[$day] ?? 0) + $count;
                    }
                }
            }
        }

        ksort($days);
        return new JSONResponse(['days' => $days]);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function libraryPhotos(): JSONResponse {
        $userId = $this->uid();
        if ($userId === null) {
            return $this->noUser();
        }
        $from = (string)$this->request->getParam('from', '');
        $to = (string)$this->request->getParam('to', '');
        if ($from === '' || $to === '') {
            return new JSONResponse(['error' => 'from and to are required'], 400);
        }
        return new JSONResponse(['photos' => $this->photoFetcher->fetchForRange($userId, $from, $to)]);
    }

    // -- helpers --------------------------------------------------------------

    private function uid(): ?string {
        $user = $this->userSession->getUser();
        return $user ? $user->getUID() : null;
    }

    private function noUser(): JSONResponse {
        return new JSONResponse(['error' => 'No user'], 401);
    }

    private function notFound(): JSONResponse {
        return new JSONResponse(['error' => 'Not found'], 404);
    }

    private function displayName(string $uid): string {
        $user = $this->userManager->get($uid);
        return $user ? $user->getDisplayName() : $uid;
    }

    /** @return array<int,array{uid:string,label:string,isMe:bool}> */
    private function contributors(string $userId, int $journalId): array {
        $out = [];
        foreach ($this->journalService->listConsentingUsers($journalId) as $uid) {
            $out[] = ['uid' => $uid, 'label' => $this->displayName($uid), 'isMe' => $uid === $userId];
        }
        return $out;
    }

    private function publicUrl(string $token): string {
        return $this->urlGenerator->linkToRouteAbsolute('journeys.publicDiary.show', ['token' => $token]);
    }

    /** Collect only the request params that are actually present. */
    private function pick(array $keys): array {
        $out = [];
        foreach ($keys as $key) {
            $value = $this->request->getParam($key, '__absent__');
            if ($value !== '__absent__') {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /**
     * Resolve + persist the entry location from its photos; return the values.
     * Only overwrites the cached location when the resolver actually found a
     * coordinate — so adding a non-GPS photo doesn't wipe a known location.
     */
    private function applyLocation(string $userId, int $entryId, array $fileIds): array {
        $location = $this->locationResolver->resolveForFileIds($userId, $fileIds);
        if ($location['lat'] === null) {
            return $location;
        }
        try {
            $this->journalService->updateEntry($userId, $entryId, $location);
        } catch (JournalNotFoundException $e) {
            // entry vanished concurrently; ignore
        }
        return $location;
    }

    private function loadEntry(string $userId, int $journalId, int $entryId): ?JournalEntry {
        return $this->journalService->getEntry($journalId, $entryId);
    }

    private function serializeJournal(?Journal $journal, bool $withEntries = false, ?string $userId = null): ?array {
        if ($journal === null) {
            return null;
        }
        $data = [
            'id' => $journal->id,
            'title' => $journal->title,
            'description' => $journal->description,
            'coverFileid' => $journal->coverFileid,
            'startDate' => $journal->startDate,
            'endDate' => $journal->endDate,
            'isPublic' => $journal->isPublic(),
            'isCompleted' => $journal->isCompleted(),
            'completedAt' => $journal->completedAt,
            'isOwner' => $userId !== null && $journal->userId === $userId,
            'shareUrl' => $journal->isPublic() ? $this->publicUrl($journal->publicToken) : null,
        ];
        if ($withEntries) {
            $data['entries'] = array_map([$this, 'serializeEntry'], $journal->entries);
            $data['stats'] = JournalStats::compute(
                JournalStats::rowsFromEntries($journal->entries),
                array_sum(array_map(static fn(JournalEntry $e) => count($e->photos), $journal->entries))
            );
            if ($userId !== null) {
                $data['myLibraryShared'] = $this->journalService->hasLibraryConsent($journal->id, $userId);
                $data['libraryContributors'] = $this->contributors($userId, $journal->id);
            }
        }
        return $data;
    }

    private function serializeEntry(?JournalEntry $entry): ?array {
        if ($entry === null) {
            return null;
        }
        return [
            'id' => $entry->id,
            'journalId' => $entry->journalId,
            'date' => $entry->entryDate,
            'title' => $entry->title,
            'body' => $entry->body,
            'sortOrder' => $entry->sortOrder,
            'location' => [
                'lat' => $entry->lat,
                'lon' => $entry->lon,
                'placeLabel' => $entry->placeLabel,
                'city' => $entry->city,
                'country' => $entry->country,
                'countryCode' => $entry->countryCode,
            ],
            'photos' => array_map([$this, 'serializePhoto'], $entry->photos),
        ];
    }

    private function serializePhoto(EntryPhoto $photo): array {
        return [
            'id' => $photo->id,
            'fileid' => $photo->fileid,
            'sortOrder' => $photo->sortOrder,
            'caption' => $photo->caption,
            'takenAt' => $photo->takenAt,
        ];
    }
}
