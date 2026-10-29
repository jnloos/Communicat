<?php

namespace App\Discussion\Pipelines;

use App\Discussion\Selectors\JevBidSelector;
use App\Discussion\Stages\JevBids;
use App\Discussion\Stages\ThinkOnly;
use App\Discussion\Values\BidResolution;
use App\Discussion\Values\JevDecides;

/**
 * Score with the bid left entirely to Jev (see DecidesWithJev): the team model
 * only thinks and is never asked for a priority; Jev reads each agent's thought
 * and gives the bid.
 *
 *   ThinkOnly → JevBids → SelectSpeaker(HighestBidSelector) → Speak ...
 *
 * The thought is written under think/thought, not under think/prioritize, so the
 * agent does not write it knowing a number follows, and the team model's own bid
 * does not exist (jev_own_bids is null for everyone). What is left of the team
 * model is the thought Jev reads. Not comparable with ScorePipeline for the same
 * reason: a series of its own. (An earlier JevScorePipeline asked the team model
 * for thought and priority together and let Jev replace only the number; it was
 * dropped on 20.10.2026, its cells deleted.)
 *
 * The selector is HighestBidSelector on Jev's rounded bids unless
 * config('ai.jev.bid_resolution') is 'unrounded' (JevBidSelector).
 */
class JevScorePipeline extends ScorePipeline implements DeclaresDecisionModel
{
    use DecidesWithJev;

    public function stages(): array
    {
        $stages = parent::stages();
        array_splice($stages, 0, 1, [ThinkOnly::class, JevBids::class]);

        return $stages;
    }

    protected function selector(): string
    {
        return BidResolution::fromConfig() === BidResolution::Unrounded ? JevBidSelector::class : parent::selector();
    }

    protected function decides(): JevDecides
    {
        return JevDecides::Bidder;
    }
}
