<?php

namespace App\Discussion\Pipelines;

use App\Discussion\Selectors\HighestBidSelector;
use App\Discussion\Selectors\SpeakerSelector;
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
            SelectSpeaker::with($this->selector()),
            Speak::class,
            PersistMessage::class,
            Summarize::class,
        ];
    }

    /**
     * The one thing a variant of this pipeline may change: who grants the floor.
     * Every other stage stays as listed in stages().
     *
     * @return class-string<SpeakerSelector>
     */
    protected function selector(): string
    {
        return HighestBidSelector::class;
    }
}
