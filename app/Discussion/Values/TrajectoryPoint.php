<?php

namespace App\Discussion\Values;

/** Every participant's shares right after one spoken turn. */
final readonly class TrajectoryPoint
{
    /**
     * @param  int  $turn  the spoken turn this point follows, counted from 1;
     *                     turns that failed before speaking are not counted
     * @param  int  $speakerId  the expert who spoke in this turn
     * @param  array<string, array<int, float>>  $shares  per ShareSeries value, the
     *                                                    share 0..1 keyed by expert id
     */
    public function __construct(
        public int $turn,
        public int $speakerId,
        public array $shares,
    ) {}

    /** 0.0 for an expert who was not seated: a share they never had. */
    public function share(ShareSeries $series, int $expertId): float
    {
        return $this->shares[$series->value][$expertId] ?? 0.0;
    }
}
