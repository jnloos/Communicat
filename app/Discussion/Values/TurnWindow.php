<?php

namespace App\Discussion\Values;

/**
 * The turns every run of an answer is read over: the asked window, its upper
 * bound capped at the shortest run that contributes. As asked when no run spoke.
 */
final readonly class TurnWindow
{
    /** The window as read, its upper bound already capped. */
    public TurnRange $turns;

    public function __construct(
        public TurnRange $asked,
        /** Turns the shortest contributing run has; null when no run spoke. */
        public ?int $shortestRun,
    ) {
        $this->turns = $shortestRun === null ? $asked : $asked->cappedAt($shortestRun);
    }

    /** The upper bound is the shortest run's length, not what was asked. */
    public function isCapped(): bool
    {
        return $this->shortestRun !== null && ($this->asked->to === null || $this->asked->to > $this->shortestRun);
    }

    /**
     * The window starts past the shortest run. The capped bound cannot make it
     * empty for the longer runs (it may not fall below the start), so no run is
     * read and all are counted as excluded.
     */
    public function startsBeyondShortestRun(): bool
    {
        return $this->shortestRun !== null && $this->asked->from > $this->shortestRun;
    }
}
