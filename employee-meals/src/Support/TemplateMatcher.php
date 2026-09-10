<?php

namespace EmployeeMeals\Support;

/**
 * Compare two minutiae templates, in PHP.
 *
 * The expensive half of fingerprint work is turning a picture into points, and
 * that now happens in the browser on the till PC (resources/js/fpextract.js).
 * What is left is comparing small lists of coordinates, which PHP can do - so
 * the server needs nothing installed at all. No Python, no OpenCV, no host
 * permissions, and nothing to break when the site moves.
 *
 * Same algorithm as the Python matcher: a Hough vote over the rigid transform,
 * then a count of pairs that genuinely agree under the winning transform.
 *
 * ⚠️ The tolerances are not taste. The Hough stage maximises over ~20 rotations
 * and many translations, and a maximum over a large search space flatters
 * itself: loose tolerances let an impostor find some transform that lines a lot
 * of points up by chance.
 */
class TemplateMatcher
{
    private const DIST_TOL = 8.0;
    private const ANGLE_TOL = 0.2618;      // 15 degrees
    private const HOUGH_BIN = 6.0;
    private const ROT_FROM = -30;
    private const ROT_TO = 30;
    private const ROT_STEP = 2;

    /**
     * How many minutiae genuinely line up between two templates.
     *
     * @param list<array{x:int,y:int,t:string,a:float}> $probe
     * @param list<array{x:int,y:int,t:string,a:float}> $candidate
     */
    public function score(array $probe, array $candidate): int
    {
        if ($probe === [] || $candidate === []) {
            return 0;
        }

        // Splitting by type once keeps the inner loops from re-testing it, and
        // an ending is never paired with a bifurcation.
        $byType = ['end' => [], 'bif' => []];
        foreach ($candidate as $b) {
            $byType[$b['t']][] = $b;
        }

        $bestVotes = 0;
        $bestTransform = null;

        for ($deg = self::ROT_FROM; $deg <= self::ROT_TO; $deg += self::ROT_STEP) {
            $rad = deg2rad($deg);
            $cos = cos($rad);
            $sin = sin($rad);
            $votes = [];

            foreach ($probe as $a) {
                $rx = $a['x'] * $cos - $a['y'] * $sin;
                $ry = $a['x'] * $sin + $a['y'] * $cos;

                foreach ($byType[$a['t']] ?? [] as $b) {
                    $dx = (int) round(($b['x'] - $rx) / self::HOUGH_BIN);
                    $dy = (int) round(($b['y'] - $ry) / self::HOUGH_BIN);
                    $key = $dx.':'.$dy;
                    $votes[$key] = ($votes[$key] ?? 0) + 1;
                }
            }

            if ($votes === []) {
                continue;
            }

            $n = max($votes);
            if ($n > $bestVotes) {
                $bestVotes = $n;
                [$dx, $dy] = explode(':', (string) array_search($n, $votes, true));
                $bestTransform = [$rad, ((int) $dx) * self::HOUGH_BIN, ((int) $dy) * self::HOUGH_BIN];
            }
        }

        if ($bestTransform === null) {
            return 0;
        }

        [$rad, $tx, $ty] = $bestTransform;
        $cos = cos($rad);
        $sin = sin($rad);

        $used = [];
        $paired = 0;

        foreach ($probe as $a) {
            $ax = $a['x'] * $cos - $a['y'] * $sin + $tx;
            $ay = $a['x'] * $sin + $a['y'] * $cos + $ty;
            $aa = fmod($a['a'] + $rad, M_PI);

            // ⚠️ Pair with the NEAREST qualifying candidate, not the first
            // found: a far-but-inside-tolerance point would otherwise consume
            // the slot its true partner needed, quietly inflating scores.
            $nearest = null;
            $nearestDistance = self::DIST_TOL;

            foreach ($candidate as $i => $b) {
                if (isset($used[$i]) || $b['t'] !== $a['t']) {
                    continue;
                }

                $dx = $ax - $b['x'];
                $dy = $ay - $b['y'];
                $d = sqrt($dx * $dx + $dy * $dy);
                if ($d > $nearestDistance) {
                    continue;
                }

                $da = fmod(abs($aa - $b['a']), M_PI);
                if (min($da, M_PI - $da) > self::ANGLE_TOL) {
                    continue;
                }

                $nearest = $i;
                $nearestDistance = $d;
            }

            if ($nearest !== null) {
                $used[$nearest] = true;
                $paired++;
            }
        }

        return $paired;
    }

    /**
     * Best score of a probe against every stored touch for one person.
     *
     * An enrolment is several touches of several fingers and the BEST counts -
     * averaging punishes one crooked press.
     *
     * @param list<array{x:int,y:int,t:string,a:float}> $probe
     * @param list<list<array{x:int,y:int,t:string,a:float}>> $samples
     */
    public function bestAgainst(array $probe, array $samples): int
    {
        $best = 0;
        foreach ($samples as $sample) {
            $score = $this->score($probe, $sample);
            if ($score > $best) {
                $best = $score;
            }
        }

        return $best;
    }
}
