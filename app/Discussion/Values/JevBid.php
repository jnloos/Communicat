<?php

namespace App\Discussion\Values;

/**
 * Jev's urgency for one agent, in plain scalars so it can come back from a
 * child process (ParallelPrompts). `value` is the unrounded expected level on
 * the bids' own scale (1 to 5, read by JevScore); `level()` is the integer bid
 * HighestBidSelector reads. Both are null when the answer was unreadable or the
 * request failed.
 */
final readonly class JevBid
{
    public function __construct(
        public ?float $value,
        public ?float $confidence,
        public ?string $model,
        public int $latencyMs,
    ) {}

    /**
     * The expected level rounded to the nearest whole bid (a .5 goes up). The
     * expectation is used rather than the most probable level because it keeps
     * the mass of the whole distribution (and so treats the ordinal levels as
     * equally spaced, see BidResolution); the price is that two agents with close
     * expectations tie on the integer, and a tie goes to the TieBreaker like any
     * other Score tie. JevScore guarantees 1 to 5, so no clamp is needed.
     */
    public function level(): ?int
    {
        return $this->value === null ? null : (int) round($this->value);
    }

    /** Jev gave nothing readable for this agent. */
    public function unreadable(): bool
    {
        return $this->value === null;
    }
}
