<?php

namespace App\Discussion\Metrics\Questions;

use App\Discussion\Values\GroupResult;
use App\Discussion\Values\RunValue;
use App\Discussion\Values\TrajectoryMean;

/** Gathers one group's runs while QuestionEvaluator walks them; mutable, and never leaves it. */
final class GroupCollector
{
    /** @var list<RunValue> */
    private array $runs = [];

    /** @var array<int, float> turn => summed share */
    private array $sums = [];

    /** @var array<int, int> turn => runs that reached it */
    private array $counts = [];

    /** @param  array<int, float>  $curve  turn => the group's share after it; empty without a curve */
    public function add(RunValue $run, array $curve): void
    {
        $this->runs[] = $run;

        foreach ($curve as $turn => $share) {
            $this->sums[$turn] = ($this->sums[$turn] ?? 0.0) + $share;
            $this->counts[$turn] = ($this->counts[$turn] ?? 0) + 1;
        }
    }

    public function result(string $key, string $label): GroupResult
    {
        ksort($this->sums);
        $curve = [];

        foreach ($this->sums as $turn => $sum) {
            $curve[] = new TrajectoryMean($turn, $sum / $this->counts[$turn], $this->counts[$turn]);
        }

        return new GroupResult($key, $label, $this->runs, $curve);
    }
}
