<?php

namespace App\Discussion\Pipelines;

use App\Discussion\Selectors\OrchestratorSelector;
use App\Discussion\Stages\PersistMessage;
use App\Discussion\Stages\SelectSpeaker;
use App\Discussion\Stages\Speak;
use App\Discussion\Stages\Summarize;
use App\Discussion\Stages\ThinkAsSpeaker;

/** A model picks the next speaker from the history; only that speaker thinks. */
class OrchestratorPipeline implements TurnPipeline
{
    public function stages(): array
    {
        return [
            SelectSpeaker::with(OrchestratorSelector::class),
            ThinkAsSpeaker::class,
            Speak::class,
            PersistMessage::class,
            Summarize::class,
        ];
    }
}
