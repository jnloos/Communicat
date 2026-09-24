<?php

namespace App\Discussion\Pipelines;

use App\Discussion\Selectors\HighestBidSelector;
use App\Discussion\Stages\PersistMessage;
use App\Discussion\Stages\SelectSpeaker;
use App\Discussion\Stages\Speak;
use App\Discussion\Stages\Summarize;
use App\Discussion\Stages\ThinkAndPrioritize;

/** Everyone bids, the highest bid speaks. Priority bidding, as in Tak2026. */
class ScorePipeline implements TurnPipeline
{
    public function stages(): array
    {
        return [
            ThinkAndPrioritize::class,
            SelectSpeaker::with(HighestBidSelector::class),
            Speak::class,
            PersistMessage::class,
            Summarize::class,
        ];
    }
}
