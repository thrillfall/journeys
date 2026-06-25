<?php
namespace OCA\Journeys\Controller;

use OCA\Journeys\Model\JournalEntry;
use OCA\Journeys\Service\PhotoPreviewResponder;
use OCA\Journeys\Service\JournalService;
use OCA\Journeys\Service\StaticRouteMapService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Util;

/**
 * Anonymous, read-only public view of a shared journal (Increment 3).
 * Server-rendered HTML at /s/{token}; photos streamed via a token-validated
 * preview endpoint. No login required.
 */
class PublicDiaryController extends Controller {

    public function __construct(
        $appName,
        IRequest $request,
        private JournalService $journalService,
        private PhotoPreviewResponder $photoResponder,
        private StaticRouteMapService $routeMapService,
        private IURLGenerator $urlGenerator,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @PublicPage
     * @NoCSRFRequired
     */
    public function show(string $token) {
        $journal = $this->journalService->getJournalByToken($token);
        if ($journal === null) {
            return new NotFoundResponse();
        }
        $entries = $this->journalService->listEntries($journal->id);

        $viewEntries = array_map(fn(JournalEntry $e) => [
            'date' => $e->entryDate,
            'title' => $e->title,
            'body' => $e->body,
            'place' => $this->placeText($e),
            'photos' => array_map(fn($p) => [
                'thumb' => $this->photoUrl($token, $p->fileid, 'thumb'),
                'url' => $this->photoUrl($token, $p->fileid, 'large'),
                'caption' => $p->caption,
            ], $e->photos),
        ], $entries);

        $overview = $this->buildOverview($entries);
        // The map image is generated lazily by the map() endpoint; here we only
        // decide whether to emit the <img> (route needs 2+ distinct points).
        $mapUrl = count($this->buildMapPoints($entries)) >= 2
            ? $this->urlGenerator->linkToRoute('journeys.publicDiary.map', ['token' => $token])
            : null;
        // Cover: explicit journal cover (if still attached) else first available photo.
        $cover = null;
        if ($journal->coverFileid && $this->journalService->journalHasPhoto($journal->id, $journal->coverFileid)) {
            $cover = $this->photoUrl($token, $journal->coverFileid, 'large');
        }
        if ($cover === null) {
            $cover = $this->firstPhotoUrl($token, $entries);
        }

        // OpenGraph / Twitter tags for nice link previews in chat apps.
        Util::addHeader('meta', ['property' => 'og:title', 'content' => $journal->title]);
        Util::addHeader('meta', ['property' => 'og:type', 'content' => 'article']);
        if ($journal->description) {
            Util::addHeader('meta', ['property' => 'og:description', 'content' => $journal->description]);
        }
        if ($cover) {
            Util::addHeader('meta', ['property' => 'og:image', 'content' => $cover]);
        }

        return new TemplateResponse('journeys', 'public', [
            'title' => $journal->title,
            'description' => $journal->description,
            'overview' => $overview,
            'entries' => $viewEntries,
            'cover' => $cover,
            'mapUrl' => $mapUrl,
        ], TemplateResponse::RENDER_AS_PUBLIC);
    }

    /**
     * @PublicPage
     * @NoCSRFRequired
     */
    public function photo(string $token, int $fileid) {
        $journal = $this->journalService->getJournalByToken($token);
        if ($journal === null) {
            return new NotFoundResponse();
        }
        // Hard rule: only fileids attached to an entry of THIS journal are servable.
        if (!$this->journalService->journalHasPhoto($journal->id, $fileid)) {
            return new NotFoundResponse();
        }
        // Serve under the photo's owner_uid (a contributor's own storage),
        // falling back to the journal owner (robust against stale owner_uid).
        $owner = $this->journalService->getPhotoOwnerUid($journal->id, $fileid);
        return $this->photoResponder->serve([$owner, $journal->userId], $fileid, $this->request->getParam('size'));
    }

    /**
     * Static PNG travel-route map for the journal, generated/cached server-side.
     * @PublicPage
     * @NoCSRFRequired
     */
    public function map(string $token) {
        $journal = $this->journalService->getJournalByToken($token);
        if ($journal === null) {
            return new NotFoundResponse();
        }
        $points = $this->buildMapPoints($this->journalService->listEntries($journal->id));
        $png = $this->routeMapService->pngForPoints($points);
        if ($png === null) {
            return new NotFoundResponse();
        }
        $resp = new DataDisplayResponse($png, Http::STATUS_OK, ['Content-Type' => 'image/png']);
        $resp->cacheFor(86400, false, true);
        return $resp;
    }

    // -- helpers --------------------------------------------------------------

    private function photoUrl(string $token, int $fileid, string $size): string {
        // Absolute — these feed the public <img> tags AND the OpenGraph/Twitter
        // image meta, which crawlers require to be absolute URLs.
        return $this->urlGenerator->linkToRouteAbsolute('journeys.publicDiary.photo',
            ['token' => $token, 'fileid' => $fileid]) . '?size=' . $size;
    }

    /** @param JournalEntry[] $entries */
    private function firstPhotoUrl(string $token, array $entries): ?string {
        foreach ($entries as $e) {
            if (!empty($e->photos)) {
                return $this->photoUrl($token, $e->photos[0]->fileid, 'large');
            }
        }
        return null;
    }

    private function placeText(JournalEntry $e): ?string {
        if ($e->placeLabel) {
            return $e->country && stripos($e->placeLabel, $e->country) === false
                ? $e->placeLabel . ', ' . $e->country
                : $e->placeLabel;
        }
        return $e->city ?? $e->country;
    }

    /**
     * Geolocated entries in chronological order, for the inline SVG route map.
     * Only entries that carry coordinates are plotted; the label is the entry's
     * resolved place (falling back to its date). Returned empty when fewer than
     * two distinct coordinates exist (a one-point "map" isn't worth drawing).
     * @param JournalEntry[] $entries
     * @return array<int,array{lat:float,lon:float,label:string}>
     */
    private function buildMapPoints(array $entries): array {
        $points = [];
        foreach ($entries as $e) {
            if ($e->lat === null || $e->lon === null) {
                continue;
            }
            $points[] = [
                'lat' => $e->lat,
                'lon' => $e->lon,
                'label' => $this->placeText($e) ?? $e->entryDate,
            ];
        }
        $distinct = [];
        foreach ($points as $p) {
            $distinct[round($p['lat'], 4) . ',' . round($p['lon'], 4)] = true;
        }
        return count($distinct) >= 2 ? $points : [];
    }

    /**
     * Distinct countries (in first-seen order) each with their distinct cities.
     * @param JournalEntry[] $entries
     * @return array<int,array{country:string,cities:string[]}>
     */
    private function buildOverview(array $entries): array {
        $order = [];
        $byCountry = [];
        foreach ($entries as $e) {
            $country = $e->country;
            if (!$country) {
                continue;
            }
            if (!isset($byCountry[$country])) {
                $byCountry[$country] = [];
                $order[] = $country;
            }
            $city = $e->city ?: ($e->placeLabel ?: null);
            if ($city && !in_array($city, $byCountry[$country], true)) {
                $byCountry[$country][] = $city;
            }
        }
        $out = [];
        foreach ($order as $country) {
            $out[] = ['country' => $country, 'cities' => $byCountry[$country]];
        }
        return $out;
    }
}
