<?php

namespace App\Discussion\Values;

/**
 * A turn's token count for one figure (in, out, reasoning), summed over its
 * calls. A call that did not report the figure is NULL, never 0, so the sum
 * is either complete (every call reported), partial (some did) or absent
 * (none did). The study export takes only a complete sum; the debug panel
 * shows a partial one as a lower bound.
 */
final readonly class TokenTotal
{
    public function __construct(
        /** Sum over the calls that reported; null when none did. */
        public ?int $sum,
        /** Whether every call of the turn reported the figure. */
        public bool $complete,
    ) {}

    /** @param  iterable<?int>  $counts  one entry per call */
    public static function of(iterable $counts): self
    {
        $sum = null;
        $complete = true;

        foreach ($counts as $count) {
            if ($count === null) {
                $complete = false;
            } else {
                $sum = ($sum ?? 0) + $count;
            }
        }

        return new self($sum, $complete && $sum !== null);
    }

    /** The sum only when it is complete: what an analysis may read as the turn's count. */
    public function exact(): ?int
    {
        return $this->complete ? $this->sum : null;
    }
}
