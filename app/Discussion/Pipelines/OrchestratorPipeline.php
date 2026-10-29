<?php

namespace App\Discussion\Pipelines;

use App\Discussion\Selectors\OrchestratorSelector;
use App\Discussion\Selectors\SpeakerSelector;
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
            SelectSpeaker::with($this->selector()),
            ThinkAsSpeaker::class,
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
        return OrchestratorSelector::class;
    }
}
