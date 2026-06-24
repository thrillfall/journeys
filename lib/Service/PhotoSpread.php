<?php
namespace OCA\Journeys\Service;

/**
 * Picks a representative subset of a day's photos, spread evenly across the day
 * by capture time — so a new journal entry is pre-seeded with a lean selection
 * (not every burst-shot frame), which the user then curates. Pure / unit-tested.
 */
class PhotoSpread {

    /**
     * @param array<int,array{fileid:int,datetaken?:string}> $photos ordered by datetaken ascending
     * @param int $max maximum number to pick
     * @return array<int,array{fileid:int,datetaken?:string}> chosen photos, in time order
     */
    public static function pick(array $photos, int $max = 20): array {
        if ($max < 1) {
            return [];
        }
        $photos = array_values($photos);
        $n = count($photos);
        if ($n <= $max) {
            return $photos;
        }

        $epochs = array_map(
            static fn(array $p) => isset($p['datetaken']) ? (int)strtotime((string)$p['datetaken']) : 0,
            $photos
        );

        // Farthest-point sampling by capture time: seed with the day's first and
        // last photo, then repeatedly add the photo whose nearest already-picked
        // neighbour is the furthest away in time. This spreads picks across the
        // whole day, takes at most ~one frame per burst (a burst's other frames
        // have ~0 distance to the first pick), and never piles multiple picks
        // into an empty stretch — unlike "nearest photo to evenly-spaced times",
        // which duplicates burst frames around temporal gaps.
        $picked = [0 => true, ($n - 1) => true];
        $minDist = [];
        for ($i = 0; $i < $n; $i++) {
            $minDist[$i] = min(abs($epochs[$i] - $epochs[0]), abs($epochs[$i] - $epochs[$n - 1]));
        }
        while (count($picked) < $max) {
            $best = -1;
            $bestVal = -1;
            for ($i = 0; $i < $n; $i++) {
                if (isset($picked[$i])) {
                    continue;
                }
                if ($minDist[$i] > $bestVal) {
                    $bestVal = $minDist[$i];
                    $best = $i;
                }
            }
            if ($best < 0) {
                break;
            }
            $picked[$best] = true;
            for ($i = 0; $i < $n; $i++) {
                if (isset($picked[$i])) {
                    continue;
                }
                $d = abs($epochs[$i] - $epochs[$best]);
                if ($d < $minDist[$i]) {
                    $minDist[$i] = $d;
                }
            }
        }

        $idx = array_keys($picked);
        sort($idx);
        return array_map(static fn(int $i) => $photos[$i], $idx);
    }
}
