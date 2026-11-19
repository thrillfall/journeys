<?php
namespace OCA\Journeys\Service;

use OCA\Journeys\Model\Image;
use OCA\Journeys\Model\VideoStyle;
use OCA\Journeys\Model\YearReviewChapter;
use OCA\Journeys\Model\YearReviewSelection;
use OCA\Journeys\Model\YearReviewStats;
use OCP\IConfig;
use OCP\IL10N;
use OCP\L10N\IFactory;

/**
 * Turns a year-in-review selection into what the renderer plays: the opening
 * montage, a map card before each journey, the photos, and the closing card,
 * plus a heading for every month and a slide where a chapter changes.
 */
class YearReviewComposer {
    public const CHAPTER_TRANSITION = 'slideleft';
    public const CLOSING_TRANSITION = 'fadeblack';

    public function __construct(
        private YearReviewCardRenderer $cards,
        private YearReviewImageProvider $provider,
        private HomeService $homeService,
        private IFactory $l10nFactory,
        private IConfig $config,
    ) {}

    /**
     * @param string[] $files prepared files, parallel to $images
     * @param Image[] $images
     * @return array{files:string[], style:VideoStyle, captions:array<string,?string>, title:?string}
     */
    public function compose(string $user, YearReviewSelection $selection, string $dir, array $files, array $images, bool $landscape, int $fps, bool $captions): array {
        [$w, $h] = $landscape ? [1920, 1080] : [1080, 1920];
        $files = array_values($files);
        $images = array_values($images);
        $l = $this->l10n($user);
        $lang = $this->language($user);

        $items = [];
        $intro = $this->cards->intro($dir, $this->montageStills($files, $images, $landscape), (string)$selection->year, $w, $h, $fps);
        if ($intro !== null) {
            $items[] = ['path' => $intro, 'month' => null, 'chapter' => null, 'closing' => false];
        }

        $home = $this->homeService->getHomeFromConfig($user);
        $mapped = [];
        foreach ($files as $i => $path) {
            $img = $images[$i] ?? null;
            $chapterIndex = $img !== null ? ($selection->chapterByFileId[$img->fileid] ?? null) : null;
            $chapter = $chapterIndex !== null ? ($selection->chapterObjects[$chapterIndex] ?? null) : null;
            $month = $img !== null ? (int)substr($img->datetaken, 5, 2) : null;

            if ($chapter !== null && $chapter->isJourney() && !array_key_exists($chapterIndex, $mapped)) {
                $card = $this->mapCardFor($dir, count($mapped), $chapter, $this->backdropStill($files, $images, $selection, $chapterIndex, $landscape) ?? $path, $home, $lang, $w, $h, $fps);
                $mapped[$chapterIndex] = $card !== null;
                if ($card !== null) {
                    $items[] = ['path' => $card, 'month' => $month, 'chapter' => $chapterIndex, 'closing' => false];
                }
            }
            $items[] = ['path' => $path, 'month' => $month, 'chapter' => $chapterIndex, 'closing' => false];
        }

        $stats = $selection->stats;
        $statsBackground = $this->statsBackground($files, $images, $selection, $landscape);
        if ($stats !== null && $statsBackground !== null) {
            $card = $this->cards->statsCard($dir, $statsBackground, (string)$selection->year, $this->statsLines($stats, $l, $lang), $w, $h, $fps);
            if ($card !== null) {
                $items[] = ['path' => $card, 'month' => null, 'chapter' => null, 'closing' => true];
            }
        }

        $marks = self::chapterMarks(array_map(fn(array $item) => [
            'basename' => pathinfo($item['path'], PATHINFO_FILENAME),
            'month' => $item['month'],
            'chapter' => $item['chapter'],
            'closing' => $item['closing'],
        ], $items));
        $headings = [];
        foreach ($marks['headings'] as $basename => $month) {
            $headings[$basename] = $this->provider->monthName($user, $selection->year, $month);
        }

        $captionMap = [];
        if ($captions) {
            foreach ($files as $i => $path) {
                $img = $images[$i] ?? null;
                $chapterIndex = $img !== null ? ($selection->chapterByFileId[$img->fileid] ?? null) : null;
                if ($chapterIndex !== null && empty($mapped[$chapterIndex]) && ($selection->chapterObjects[$chapterIndex] ?? null)?->isJourney()) {
                    $captionMap[pathinfo($path, PATHINFO_FILENAME)] = $selection->captionsByFileId[$img->fileid] ?? null;
                }
            }
        }

        return [
            'files' => array_column($items, 'path'),
            'style' => new VideoStyle(
                chronologicalStacks: true,
                keepOrder: true,
                size: [$w, $h],
                headingsByBasename: $headings,
                transitionsByBasename: $marks['transitions'],
            ),
            'captions' => $captionMap,
            'title' => $intro === null ? (string)$selection->year : null,
        ];
    }

    /**
     * A month heading on the first item of each month; a slide into every
     * item that opens a new month or chapter; a fade through black into the
     * closing card.
     *
     * @param array<int,array{basename:string,month:?int,chapter:?int,closing:bool}> $items in play order
     * @return array{headings: array<string,int>, transitions: array<string,string>}
     */
    public static function chapterMarks(array $items): array {
        $headings = [];
        $transitions = [];
        $prevMonth = null;
        $prevChapter = null;
        $started = false;
        foreach ($items as $item) {
            if ($item['closing']) {
                $transitions[$item['basename']] = self::CLOSING_TRANSITION;
                continue;
            }
            if ($item['month'] === null) {
                continue;
            }
            $newMonth = $item['month'] !== $prevMonth;
            if ($newMonth) {
                $headings[$item['basename']] = $item['month'];
            }
            if ($started && ($newMonth || $item['chapter'] !== $prevChapter)) {
                $transitions[$item['basename']] = self::CHAPTER_TRANSITION;
            }
            $prevMonth = $item['month'];
            $prevChapter = $item['chapter'];
            $started = true;
        }
        return ['headings' => $headings, 'transitions' => $transitions];
    }

    /**
     * @return string[]
     */
    public function statsLines(YearReviewStats $stats, IL10N $l, string $lang): array {
        $number = class_exists(\NumberFormatter::class) ? new \NumberFormatter($lang, \NumberFormatter::DECIMAL) : null;
        $fmt = fn(int $n) => $number?->format($n) ?: (string)$n;
        $lines = [];
        if ($stats->trips > 0) {
            $line = $l->n('%n trip', '%n trips', $stats->trips);
            if ($stats->daysAway > 0) {
                $line .= ' · ' . $l->n('%n day away', '%n days away', $stats->daysAway);
            }
            $lines[] = $line;
        }
        if ($stats->excursions > 0) {
            $lines[] = $l->n('%n day trip', '%n day trips', $stats->excursions);
        }
        if (count($stats->countries) > 0) {
            $lines[] = $l->n('%n country', '%n countries', count($stats->countries));
        }
        if ($stats->farthestKm !== null && $stats->farthestKm >= 100) {
            $lines[] = $l->t('%s km from home at the farthest', [$fmt($stats->farthestKm)]);
        }
        if ($stats->photos > 0) {
            $lines[] = $l->t('%s photos', [$fmt($stats->photos)]);
        }
        return $lines;
    }

    /**
     * Home and the journey's photo-weighted centre make the line on the map;
     * without a home, its first and last places.
     *
     * @param array{lat:float,lon:float}|null $home
     */
    private function mapCardFor(string $dir, int $index, YearReviewChapter $chapter, string $background, ?array $home, string $lang, int $w, int $h, int $fps): ?string {
        $geo = array_values(array_filter($chapter->images, fn(Image $img) => $img->lat !== null && $img->lon !== null));
        if ($geo === []) {
            return null;
        }
        if ($home !== null) {
            $lat = array_sum(array_map(fn(Image $img) => (float)$img->lat, $geo)) / count($geo);
            $lon = array_sum(array_map(fn(Image $img) => (float)$img->lon, $geo)) / count($geo);
            $points = [['lat' => (float)$home['lat'], 'lon' => (float)$home['lon']], ['lat' => $lat, 'lon' => $lon]];
        } else {
            $first = $geo[0];
            $last = $geo[count($geo) - 1];
            $points = [['lat' => (float)$first->lat, 'lon' => (float)$first->lon], ['lat' => (float)$last->lat, 'lon' => (float)$last->lon]];
        }
        return $this->cards->mapCard($dir, $index, $background, $points, $chapter->label, $this->dateRange($chapter, $lang), $w, $h, $fps);
    }

    private function dateRange(YearReviewChapter $chapter, string $lang): string {
        $first = substr($chapter->images[0]->datetaken, 0, 10);
        $last = substr($chapter->images[count($chapter->images) - 1]->datetaken, 0, 10);
        $format = function (string $day) use ($lang): string {
            $ts = strtotime($day . ' 12:00:00 UTC');
            if (class_exists(\IntlDatePatternGenerator::class)) {
                $pattern = (new \IntlDatePatternGenerator($lang))->getBestPattern('dMMMM');
                $formatted = (new \IntlDateFormatter($lang, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'UTC', null, $pattern))->format($ts);
                if (is_string($formatted) && $formatted !== '') {
                    return $formatted;
                }
            }
            return gmdate('j F', $ts);
        };
        return $first === $last ? $format($first) : $format($first) . ' – ' . $format($last);
    }

    /**
     * One still per month in the order they come, faces preferred.
     *
     * @return string[]
     */
    private function montageStills(array $files, array $images, bool $landscape): array {
        $byMonth = [];
        foreach ($files as $i => $path) {
            $img = $images[$i] ?? null;
            if ($img === null || !$this->isStill($path, $img, $landscape)) {
                continue;
            }
            $month = substr($img->datetaken, 0, 7);
            if (!isset($byMonth[$month]) || ($img->hasFaces === true && $byMonth[$month][1] !== true)) {
                $byMonth[$month] = [$path, $img->hasFaces === true];
            }
        }
        $stills = array_column(array_values($byMonth), 0);
        $count = count($stills);
        $max = YearReviewCardRenderer::MONTAGE_MAX_PHOTOS;
        if ($count <= $max) {
            return $stills;
        }
        $out = [];
        for ($i = 0; $i < $max; $i++) {
            $out[] = $stills[(int)floor($i * ($count - 1) / ($max - 1))];
        }
        return $out;
    }

    private function backdropStill(array $files, array $images, YearReviewSelection $selection, int $chapterIndex, bool $landscape): ?string {
        $fallback = null;
        foreach ($files as $i => $path) {
            $img = $images[$i] ?? null;
            if ($img === null || ($selection->chapterByFileId[$img->fileid] ?? null) !== $chapterIndex || !$this->isStill($path, $img, $landscape)) {
                continue;
            }
            if ($img->hasFaces !== true) {
                return $path;
            }
            $fallback ??= $path;
        }
        return $fallback;
    }

    /** A face-free landscape-ish photo from the longest trip makes the calmest backdrop for text. */
    private function statsBackground(array $files, array $images, YearReviewSelection $selection, bool $landscape): ?string {
        $longest = null;
        foreach ($selection->chapterObjects as $index => $chapter) {
            if ($chapter->kind === YearReviewChapter::KIND_TRIP && ($longest === null || $chapter->days() > $selection->chapterObjects[$longest]->days())) {
                $longest = $index;
            }
        }
        if ($longest !== null) {
            $still = $this->backdropStill($files, $images, $selection, $longest, $landscape);
            if ($still !== null) {
                return $still;
            }
        }
        foreach ($files as $i => $path) {
            if (isset($images[$i]) && $this->isStill($path, $images[$i], $landscape)) {
                return $path;
            }
        }
        return null;
    }

    private function isStill(string $path, Image $img, bool $landscape): bool {
        if ($img->isVideo || !in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png'], true)) {
            return false;
        }
        if ($img->w === null || $img->h === null) {
            return true;
        }
        return $landscape ? $img->w >= $img->h : $img->h > $img->w;
    }

    private function language(string $user): string {
        return $this->config->getUserValue($user, 'core', 'lang', '') ?: $this->config->getSystemValueString('default_language', 'en');
    }

    private function l10n(string $user): IL10N {
        return $this->l10nFactory->get('journeys', $this->language($user));
    }
}
