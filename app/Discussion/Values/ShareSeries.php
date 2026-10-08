<?php

namespace App\Discussion\Values;

/**
 * The four curves of a share trajectory: both of the study's units, each
 * cumulative and over a sliding window.
 *
 * Both views are needed because they answer different questions. A cumulative
 * share settles by arithmetic alone — after n turns one more turn moves it by
 * at most about 1/n — so a flat cumulative curve says the average is pinned
 * down, not that the discussion has stopped changing. Only a rolling share that
 * holds steady shows the process itself is stationary.
 *
 * The values are `{mode}_{unit}`, which is also how the CLI's columns and the
 * panel's toggles name them.
 */
enum ShareSeries: string
{
    case CumulativeTurns = 'cumulative_turns';
    case CumulativeWords = 'cumulative_words';
    case RollingTurns = 'rolling_turns';
    case RollingWords = 'rolling_words';
}
