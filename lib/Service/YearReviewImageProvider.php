<?php
namespace OCA\Journeys\Service;

use OCA\Journeys\Exception\NoImagesFoundException;
use OCA\Journeys\Model\Image;
use OCA\Journeys\Model\YearReviewChapter;
use OCA\Journeys\Model\YearReviewSelection;
use OCA\Journeys\Model\YearReviewStats;
use OCP\IConfig;

/**
 * Picks the images for a year-in-review video: one chapter per journey away
 * from home (trips and excursions), plus the everyday photos of each month.
 * Journeys near home are not chapters; their photos count as everyday.
 */
class YearReviewImageProvider {
    public const DEFAULT_MAX_IMAGES = 140;

    /** Below this quota a chapter gets no clip, or a month of two photos would be half video. */
    private const MIN_QUOTA_FOR_CLIPS = 5;
    private const FETCH_CHUNK = 500;
    /** Photos per journey looked up for its country; one place query each. */
    private const COUNTRY_SAMPLE = 25;

    private int $lastYearPhotoCount = 0;

    public function __construct(
        private ImageFetcher $imageFetcher,
        private AlbumCreator $albumCreator,
        private VideoStorySelector $selector,
        private TripClassifier $classifier,
        private YearReviewPlanner $planner,
        private IConfig $config,
        private ClusterLocationResolver $locationResolver,
        private HomeService $homeService,
    ) {}

    /**
     * @throws NoImagesFoundException
     */
    public function select(string $user, int $year, string $orientation, int $maxImages = self::DEFAULT_MAX_IMAGES, int $minGapSeconds = 5): YearReviewSelection {
        $orientation = $orientation === 'landscape' ? 'landscape' : 'portrait';
        $chapters = $this->buildChapters($user, $year, $orientation);
        if ($chapters === []) {
            throw new NoImagesFoundException(sprintf('No photos from %d.', $year));
        }

        $quota = $this->planner->allocate($chapters, max(1, $maxImages));
        $boostFaces = (bool)((int)$this->config->getUserValue($user, 'journeys', 'boostFaces', 1));
        $clips = (bool)((int)$this->config->getUserValue($user, 'journeys', 'includeVideoClips', 1));

        $selected = [];
        $captions = [];
        $summary = [];
        $chapterByFileId = [];
        foreach ($chapters as $key => $chapter) {
            $n = $quota[$key] ?? 0;
            $picked = $n > 0
                ? $this->selector->selectImages($user, $chapter->images, $minGapSeconds, $n, $boostFaces, $orientation, $clips && $n >= self::MIN_QUOTA_FOR_CLIPS)
                : [];
            if ($orientation === 'portrait') {
                $picked = self::fitLandscapesToStacks($picked);
            }
            foreach ($picked as $img) {
                $selected[] = $img;
                $captions[$img->fileid] = $chapter->label;
                $chapterByFileId[$img->fileid] = $key;
            }
            $summary[] = [
                'kind' => $chapter->kind,
                'label' => $chapter->label,
                'candidates' => count($chapter->images),
                'quota' => $n,
                'selected' => count($picked),
            ];
        }

        if ($selected === []) {
            throw new NoImagesFoundException(sprintf('No suitable photos from %d.', $year));
        }
        usort($selected, fn(Image $a, Image $b) => strcmp($a->datetaken, $b->datetaken));

        return new YearReviewSelection($year, $selected, $captions, $summary, array_values($chapters), $chapterByFileId, $this->stats($user, $chapters));
    }

    /**
     * @param YearReviewChapter[] $chapters
     */
    public function stats(string $user, array $chapters): YearReviewStats {
        $stats = new YearReviewStats(photos: $this->lastYearPhotoCount);
        $home = $this->homeService->getHomeFromConfig($user);
        $countries = [];
        foreach ($chapters as $chapter) {
            if (!$chapter->isJourney()) {
                continue;
            }
            if ($chapter->kind === YearReviewChapter::KIND_TRIP) {
                $stats->trips++;
                $stats->daysAway += $chapter->days();
            } else {
                $stats->excursions++;
            }
            $geo = array_values(array_filter($chapter->images, fn(Image $img) => $img->lat !== null && $img->lon !== null));
            $step = max(1, intdiv(count($geo), self::COUNTRY_SAMPLE));
            $sample = [];
            for ($i = 0; $i < count($geo); $i += $step) {
                $sample[] = $geo[$i];
            }
            $country = $sample !== [] ? $this->locationResolver->resolveClusterCountry($sample) : null;
            if ($country !== null && $country !== '') {
                $countries[$country] = true;
            }
            if ($home !== null) {
                foreach ($geo as $img) {
                    $km = (int)round($this->classifier->haversineKm((float)$img->lat, (float)$img->lon, (float)$home['lat'], (float)$home['lon']));
                    $stats->farthestKm = max($stats->farthestKm ?? 0, $km);
                }
            }
        }
        $stats->countries = array_keys($countries);
        return $stats;
    }

    /**
     * In portrait videos landscapes are shown three to a stack, so a chapter
     * with a count not divisible by three would carry its leftovers into the
     * next chapter. Drop the surplus, faceless ones first, latest first.
     *
     * @param Image[] $images
     * @return Image[]
     */
    public static function fitLandscapesToStacks(array $images): array {
        $landscapes = [];
        foreach ($images as $i => $img) {
            if (!$img->isVideo && $img->w !== null && $img->h !== null && $img->w >= $img->h) {
                $landscapes[] = $i;
            }
        }
        $surplus = count($landscapes) % 3;
        if ($surplus === 0) {
            return $images;
        }
        $faceless = array_values(array_filter($landscapes, fn(int $i) => $images[$i]->hasFaces !== true));
        $drop = array_slice(array_reverse($faceless), 0, $surplus);
        if (count($drop) < $surplus) {
            $withFaces = array_values(array_filter($landscapes, fn(int $i) => $images[$i]->hasFaces === true));
            $drop = array_merge($drop, array_slice(array_reverse($withFaces), 0, $surplus - count($drop)));
        }
        foreach ($drop as $i) {
            unset($images[$i]);
        }
        return array_values($images);
    }

    /**
     * @return YearReviewChapter[] chronological
     */
    public function buildChapters(string $user, int $year, string $orientation): array {
        $inYear = fn(Image $img) => (int)substr($img->datetaken, 0, 4) === $year;
        $fits = fn(Image $img) => $orientation !== 'landscape' || $img->w === null || $img->h === null || $img->w >= $img->h;

        $chapters = [];
        $journeyFileIds = [];
        foreach ($this->albumCreator->getTrackedClusters($user) as $row) {
            if (!$this->overlapsYear($row['start_dt'] ?? null, $row['end_dt'] ?? null, $year)) {
                continue;
            }
            $kind = $this->classifier->kind($row['is_away'] ?? null, $row['start_dt'] ?? null, $row['end_dt'] ?? null);
            if ($kind === TripClassifier::KIND_NEARBY) {
                continue;
            }
            $fileIds = $this->albumCreator->getAlbumFileIdsForUser($user, (int)$row['album_id']);
            foreach ($fileIds as $fid) {
                $journeyFileIds[(int)$fid] = true;
            }
            $images = array_values(array_filter($this->fetchByIds($user, $fileIds), fn(Image $img) => $inYear($img) && $fits($img)));
            if ($images === []) {
                continue;
            }
            $label = self::chapterLabel($row);
            $chapters[] = new YearReviewChapter(
                $kind === TripClassifier::KIND_TRIP ? YearReviewChapter::KIND_TRIP : YearReviewChapter::KIND_EXCURSION,
                $label,
                $this->sorted($images),
            );
        }

        $byMonth = [];
        $this->lastYearPhotoCount = 0;
        foreach ($this->fetchYear($user, $year) as $img) {
            if ($inYear($img)) {
                $this->lastYearPhotoCount++;
            }
            if (isset($journeyFileIds[$img->fileid]) || !$inYear($img) || !$fits($img) || !$img->isUsable()) {
                continue;
            }
            $byMonth[(int)substr($img->datetaken, 5, 2)][] = $img->fileid;
        }
        ksort($byMonth);
        foreach ($byMonth as $month => $ids) {
            $images = array_values(array_filter($this->fetchByIds($user, $ids), $inYear));
            if ($images !== []) {
                $chapters[] = new YearReviewChapter(YearReviewChapter::KIND_EVERYDAY, $this->monthName($user, $year, $month), $this->sorted($images));
            }
        }

        usort($chapters, fn(YearReviewChapter $a, YearReviewChapter $b) => strcmp($a->images[0]->datetaken, $b->images[0]->datetaken));
        return $chapters;
    }

    /**
     * A name the user gave is kept as is. Auto names repeat the dates, which
     * the year's order already tells, so the place alone is shown, without
     * the district ("València — Poblats Marítims" → "València") and with only
     * the first name of a bilingual one ("New Zealand/Aotearoa" → "New Zealand").
     */
    public static function chapterLabel(array $row): string {
        $custom = trim((string)($row['custom_name'] ?? ''));
        if ($custom !== '') {
            return $custom;
        }
        $place = trim(explode('/', explode(' — ', (string)($row['location'] ?? ''))[0])[0]);
        return $place !== '' ? $place : trim((string)($row['name'] ?? ''));
    }

    /**
     * The timeline-scoped photo list of the year. It lacks the video flag, so
     * the ids are re-read with fetchByIds before anything is selected.
     *
     * @return Image[]
     */
    private function fetchYear(string $user, int $year): array {
        $from = gmmktime(0, 0, 0, 1, 1, $year) - 86400;
        $to = gmmktime(0, 0, 0, 1, 1, $year + 1) + 86400;
        return $this->imageFetcher->fetchImagesForUser(
            $user,
            (bool)((int)$this->config->getUserValue($user, 'journeys', 'includeGroupFolders', 0)),
            (bool)((int)$this->config->getUserValue($user, 'journeys', 'includeSharedImages', 0)),
            $from,
            $to,
        );
    }

    /**
     * @param int[] $fileIds
     * @return Image[]
     */
    private function fetchByIds(string $user, array $fileIds): array {
        $out = [];
        foreach (array_chunk(array_values(array_unique($fileIds)), self::FETCH_CHUNK) as $chunk) {
            array_push($out, ...$this->imageFetcher->fetchImagesByFileIds($user, $chunk));
        }
        return $out;
    }

    /**
     * @param Image[] $images
     * @return Image[]
     */
    private function sorted(array $images): array {
        usort($images, fn(Image $a, Image $b) => strcmp($a->datetaken, $b->datetaken));
        return $images;
    }

    private function overlapsYear(?string $start, ?string $end, int $year): bool {
        $startYear = (int)substr((string)$start, 0, 4);
        $endYear = (int)substr((string)($end ?: $start), 0, 4);
        return $startYear <= $year && $endYear >= $year;
    }

    public function monthName(string $user, int $year, int $month): string {
        $lang = $this->config->getUserValue($user, 'core', 'lang', '') ?: $this->config->getSystemValueString('default_language', 'en');
        if (class_exists(\IntlDateFormatter::class)) {
            $formatter = new \IntlDateFormatter($lang, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'UTC', null, 'LLLL');
            $name = $formatter->format(gmmktime(12, 0, 0, $month, 1, $year));
            if (is_string($name) && $name !== '') {
                return mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
            }
        }
        return gmdate('F', gmmktime(12, 0, 0, $month, 1, $year));
    }
}
