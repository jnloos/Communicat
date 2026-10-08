<?php

namespace App\Discussion\Values;

use LogicException;

/**
 * What the statistics page measures. Two kinds: per-agent measures (shares and
 * words per turn) that a reference narrows down to some agents, and run-level
 * measures (Gini) that describe the whole group and ignore the reference.
 */
enum Metric: string
{
    case TurnShare = 'turn_share';
    case WordShare = 'word_share';
    case WordsPerTurn = 'words_per_turn';
    case TurnGini = 'turn_gini';
    case WordGini = 'word_gini';

    public function isRunLevel(): bool
    {
        return $this === self::TurnGini || $this === self::WordGini;
    }

    public function isShare(): bool
    {
        return $this === self::TurnShare || $this === self::WordShare;
    }

    /** Only the two shares have a curve over the run (ShareTrajectory). */
    public function hasTrajectory(): bool
    {
        return $this->isShare();
    }

    /** The trajectory series holding this metric's curve; $mode is 'cumulative' or 'rolling'. */
    public function series(string $mode): ShareSeries
    {
        $unit = $this === self::WordShare ? 'words' : 'turns';

        return ShareSeries::tryFrom("{$mode}_{$unit}") ?? ShareSeries::from("cumulative_{$unit}");
    }

    /**
     * The value for agents taken together: shares add up, and words per turn is
     * the group's words over the group's turns — never an average of averages.
     *
     * @param  list<SpeakerShare>  $speakers
     */
    public function ofAgents(array $speakers): float
    {
        $sum = fn (callable $of) => (float) array_sum(array_map($of, $speakers));

        return match ($this) {
            self::TurnShare => $sum(fn (SpeakerShare $s) => $s->turnShare),
            self::WordShare => $sum(fn (SpeakerShare $s) => $s->wordShare),
            self::WordsPerTurn => ($turns = $sum(fn (SpeakerShare $s) => $s->turns)) === 0.0
                ? 0.0
                : $sum(fn (SpeakerShare $s) => $s->words) / $turns,
            self::TurnGini, self::WordGini => throw new LogicException("{$this->value} describes the run, not a group of agents."),
        };
    }

    public function ofRun(ShareReport $report): float
    {
        return match ($this) {
            self::TurnGini => $report->turnGini,
            self::WordGini => $report->wordGini,
            default => throw new LogicException("{$this->value} is measured per agent, not per run."),
        };
    }

    public function label(): string
    {
        return __("comparisons.metrics.{$this->value}");
    }

    /** German separators (`,` decimal, `.` thousands), as the existing statistics flyout writes numbers. */
    public function format(float $value): string
    {
        return match (true) {
            $this->isShare() => number_format($value * 100, 1, ',', '.').' %',
            $this === self::WordsPerTurn => number_format($value, 1, ',', '.'),
            default => number_format($value, 2, ',', '.'),
        };
    }
}
