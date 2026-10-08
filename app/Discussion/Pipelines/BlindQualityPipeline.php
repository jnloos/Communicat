<?php

namespace App\Discussion\Pipelines;

use App\Discussion\Selectors\BlindJudgeSelector;
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
            SelectSpeaker::with(BlindJudgeSelector::class),
            Speak::class,
            PersistMessage::class,
            Summarize::class,
        ];
    }
}
