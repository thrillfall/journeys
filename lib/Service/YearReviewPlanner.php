<?php
namespace OCA\Journeys\Service;

use OCA\Journeys\Model\YearReviewChapter;

/**
 * Splits the image budget of a year-in-review video across its chapters.
 *
 * Journeys get most of it, weighted by the log of their length so a
 * three-week holiday is the biggest chapter without drowning out a weekend
 * away. The everyday months share the rest evenly, so the video still shows
 * the year between the trips. Whatever one side cannot use (too few photos)
 * goes to the other.
 */
class YearReviewPlanner {
    public const JOURNEY_SHARE = 0.7;

    private const MIN_TRIP = 3;
    private const MIN_EXCURSION = 2;
    private const MIN_EVERYDAY = 1;

    /**
     * @param YearReviewChapter[] $chapters
     * @return int[] quota per chapter, same keys as $chapters; 0 drops it
     */
    public function allocate(array $chapters, int $budget): array {
        $journeys = array_filter($chapters, fn(YearReviewChapter $c) => $c->isJourney());
        $everyday = array_filter($chapters, fn(YearReviewChapter $c) => !$c->isJourney());

        $journeyBudget = $everyday === [] ? $budget : ($journeys === [] ? 0 : (int)round($budget * self::JOURNEY_SHARE));

        $journeyQuota = $this->allocateGroup($journeys, $journeyBudget);
        $everydayQuota = $this->allocateGroup($everyday, $budget - array_sum($journeyQuota));
        $unused = $budget - array_sum($journeyQuota) - array_sum($everydayQuota);
        if ($unused > 0 && $journeys !== []) {
            $journeyQuota = $this->allocateGroup($journeys, $budget - array_sum($everydayQuota));
        }

        $out = [];
        foreach ($chapters as $key => $_) {
            $out[$key] = $journeyQuota[$key] ?? $everydayQuota[$key] ?? 0;
        }
        return $out;
    }

    public static function weight(YearReviewChapter $chapter): float {
        return match ($chapter->kind) {
            YearReviewChapter::KIND_TRIP => 1.0 + log(max(1, $chapter->days())),
            YearReviewChapter::KIND_EXCURSION => 0.6,
            default => 1.0,
        };
    }

    /**
     * @param YearReviewChapter[] $chapters
     * @return array<int|string,int>
     */
    private function allocateGroup(array $chapters, int $budget): array {
        $weights = [];
        $caps = [];
        $mins = [];
        foreach ($chapters as $key => $chapter) {
            $weights[$key] = self::weight($chapter);
            $caps[$key] = count($chapter->images);
            $mins[$key] = match ($chapter->kind) {
                YearReviewChapter::KIND_TRIP => self::MIN_TRIP,
                YearReviewChapter::KIND_EXCURSION => self::MIN_EXCURSION,
                default => self::MIN_EVERYDAY,
            };
        }
        return self::distribute($weights, $caps, $mins, $budget);
    }

    /**
     * Proportional split with per-entry floors and caps. When the floors alone
     * exceed the budget, the lightest entries are dropped (quota 0) first.
     *
     * @param array<int|string,float> $weights
     * @param array<int|string,int> $caps
     * @param array<int|string,int> $mins
     * @return array<int|string,int>
     */
    public static function distribute(array $weights, array $caps, array $mins, int $budget): array {
        $quota = array_fill_keys(array_keys($weights), 0);
        $active = array_filter($caps, fn(int $cap) => $cap > 0);
        if ($budget <= 0 || $active === []) {
            return $quota;
        }

        $floor = fn($key) => min($mins[$key] ?? 1, $caps[$key]);
        while ($active !== [] && array_sum(array_map($floor, array_keys($active))) > $budget) {
            $lightest = null;
            foreach (array_keys($active) as $key) {
                if ($lightest === null
                    || $weights[$key] < $weights[$lightest]
                    || ($weights[$key] === $weights[$lightest] && $caps[$key] < $caps[$lightest])) {
                    $lightest = $key;
                }
            }
            unset($active[$lightest]);
        }

        foreach (array_keys($active) as $key) {
            $quota[$key] = $floor($key);
        }
        $remaining = $budget - array_sum($quota);

        while ($remaining > 0) {
            $open = array_filter(array_keys($active), fn($key) => $quota[$key] < $caps[$key]);
            if ($open === []) {
                break;
            }
            $totalWeight = array_sum(array_map(fn($key) => $weights[$key], $open));
            $given = 0;
            $fractions = [];
            foreach ($open as $key) {
                $ideal = $totalWeight > 0 ? $remaining * $weights[$key] / $totalWeight : $remaining / count($open);
                $add = min((int)floor($ideal), $caps[$key] - $quota[$key]);
                $quota[$key] += $add;
                $given += $add;
                $fractions[$key] = $ideal - floor($ideal);
            }
            $left = $remaining - $given;
            uksort($fractions, fn($a, $b) => ($fractions[$b] <=> $fractions[$a]) ?: (array_search($a, $open, true) <=> array_search($b, $open, true)));
            foreach (array_keys($fractions) as $key) {
                if ($left === 0) {
                    break;
                }
                if ($quota[$key] < $caps[$key]) {
                    $quota[$key]++;
                    $left--;
                    $given++;
                }
            }
            if ($given === 0) {
                break;
            }
            $remaining -= $given;
        }

        return $quota;
    }
}
