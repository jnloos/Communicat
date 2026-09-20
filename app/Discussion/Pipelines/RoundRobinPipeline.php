<?php

namespace App\Discussion\Pipelines;

use App\Discussion\Selectors\RoundRobinSelector;
use App\Discussion\Stages\PersistMessage;
use App\Discussion\Stages\SelectSpeaker;
use App\Discussion\Stages\Speak;
use App\Discussion\Stages\Summarize;
use App\Discussion\Stages\ThinkAsSpeaker;

/** Fixed rotation. The speaker alone thinks, after being selected. */
class RoundRobinPipeline implements TurnPipeline
{
    public function stages(): array
    {
        return [
            SelectSpeaker::with(RoundRobinSelector::class),
            ThinkAsSpeaker::class,
            Speak::class,
            PersistMessage::class,
            Summarize::class,
        ];
    }
}
