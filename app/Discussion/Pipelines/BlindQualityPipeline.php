<?php

namespace App\Discussion\Pipelines;

use App\Discussion\Selectors\BlindJudgeSelector;
use App\Discussion\Selectors\SpeakerSelector;
use App\Discussion\Stages\PersistMessage;
use App\Discussion\Stages\SelectSpeaker;
use App\Discussion\Stages\Speak;
use App\Discussion\Stages\Summarize;
use App\Discussion\Stages\ThinkAndPropose;

/**
 * QualityPipeline with a judge that cannot see status: everyone drafts, the
 * judge picks one anonymous draft, the winner speaks. Every stage but the
 * selector is the same, so a difference between the two cells is the
 * difference between a judge who sees the roles and one who does not.
 */
class BlindQualityPipeline implements TurnPipeline
{
    public function stages(): array
    {
        return [
            ThinkAndPropose::class,
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
        return BlindJudgeSelector::class;
    }
}
