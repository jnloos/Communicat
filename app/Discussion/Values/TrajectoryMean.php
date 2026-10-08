<?php

namespace App\Discussion\Values;

/** A line's mean share after one spoken turn, over the $n runs that reached it. */
final readonly class TrajectoryMean
{
    public function __construct(
        public int $turn,
        public float $mean,
        public int $n,
    ) {}
}
