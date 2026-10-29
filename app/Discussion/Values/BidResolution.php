<?php

namespace App\Discussion\Values;

/**
 * How a Jev Score cell turns Jev's urgency into the floor.
 *
 * Jev answers with a probability for each of five ordinal levels. The bid is the
 * expected level over them, which treats the five levels as equally spaced (an
 * interval scale); that is an assumption, not something the levels guarantee.
 *
 * Rounded: the expectation is rounded to a whole bid 1 to 5 and HighestBidSelector
 * (unchanged) reads it, so agents with close expectations tie and the TieBreaker
 * decides. Unrounded: JevBidSelector compares the expectations themselves (to the
 * sixth place), so a tie is a genuine one. Which of the two a study uses is decided
 * by its pilot; the default is Rounded.
 */
enum BidResolution: string
{
    case Rounded = 'rounded';
    case Unrounded = 'unrounded';

    /** @throws \ValueError for a value that is neither, so a typo in the config stops the run instead of changing it */
    public static function fromConfig(): self
    {
        return self::from((string) config('ai.jev.bid_resolution', self::Rounded->value));
    }
}
