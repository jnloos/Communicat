<?php

namespace App\Discussion\Metrics;

/**
 * The Gini coefficient of a run's distribution, the study's group-level measure
 * of inequality (Houtti2026, Rose2020).
 *
 * 0 means everyone holds the same amount. The maximum is not 1 but (n-1)/n —
 * with four agents, one agent holding everything scores 0.75. That is why
 * maximumFor() exists: a bare 0.45 is unreadable without the ceiling beside it,
 * and the ceiling moves with the group size.
 */
final class Gini
{
    /**
     * @param  list<int|float>  $values  one amount per participant, in any order
     */
    public static function of(array $values): float
    {
        $n = count($values);
        $total = array_sum($values);

        // No participants, or nobody has spoken: there is no inequality to report,
        // and the formula would divide by zero.
        if ($n === 0 || $total <= 0) {
            return 0.0;
        }

        sort($values);

        $weighted = 0.0;
        foreach ($values as $index => $value) {
            $weighted += ($index + 1) * $value;
        }

        return 2 * $weighted / ($n * $total) - ($n + 1) / $n;
    }

    /** The largest coefficient a group of this size can reach: one participant holds everything. */
    public static function maximumFor(int $n): float
    {
        return $n <= 1 ? 0.0 : ($n - 1) / $n;
    }
}
