<?php

namespace App\Discussion\Values;

use App\Discussion\Support\LocaleNumber;
use LogicException;

/**
 * What a question measures. Two kinds: per-agent measures (shares and words
 * per turn) that the question's people filter narrows down to some agents, and
 * run-level measures (Gini) that describe the whole group and ignore that filter.
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

    /** Only the two shares have a curve over the run (ShareTrajectory) and parts of a whole. */
    public function isShare(): bool
    {
        return $this === self::TurnShare || $this === self::WordShare;
    }

    /** The trajectory series holding this metric's curve. */
    public function series(CurveMode $mode): ShareSeries
    {
        $unit = $this === self::WordShare ? 'words' : 'turns';

        return ShareSeries::from("{$mode->value}_{$unit}");
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

    /**
     * The value of one group of a run. Per head divides a share by the group's
     * size, the share of its average member; words per turn is a ratio
     * already and ignores the aggregate.
     *
     * @param  non-empty-list<SpeakerShare>  $speakers
     */
    public function ofGroup(array $speakers, Aggregate $aggregate): float
    {
        $value = $this->ofAgents($speakers);

        return $aggregate === Aggregate::PerHead && $this->isShare() ? $value / count($speakers) : $value;
    }

    /**
     * What the group would get if every agent of the run took the floor (or
     * wrote) equally: its size over the run's size, or 1/n per head. The
     * reference of the redezeit-fairness studies (share against presence).
     * Only shares have one.
     */
    public function fairShare(int $groupSize, int $runSize, Aggregate $aggregate): ?float
    {
        if (! $this->isShare() || $runSize === 0) {
            return null;
        }

        return $aggregate === Aggregate::PerHead ? 1 / $runSize : $groupSize / $runSize;
    }

    public function label(): string
    {
        return __("questions.metrics.{$this->value}");
    }

    /** Separators follow the app locale, as in the statistics flyout (LocaleNumber). */
    public function format(float $value): string
    {
        return match (true) {
            $this->isShare() => LocaleNumber::percent($value),
            $this === self::WordsPerTurn => LocaleNumber::decimal($value, 1),
            default => LocaleNumber::decimal($value, 2),
        };
    }

    /** A difference of two values, always signed; percentage points for a share. */
    public function formatDelta(float $delta): string
    {
        // Round to what is shown first: -0.0004 prints as 0.0 pp and must not carry a minus.
        [$shown, $digits] = match (true) {
            $this->isShare() => [round($delta * 100, 1), 1],
            $this === self::WordsPerTurn => [round($delta, 1), 1],
            default => [round($delta, 2), 2],
        };

        $sign = match (true) {
            $shown > 0 => '+',
            $shown < 0 => '−',
            default => '±',
        };

        return $sign.LocaleNumber::decimal(abs($shown), $digits).($this->isShare() ? ' pp' : '');
    }
}
