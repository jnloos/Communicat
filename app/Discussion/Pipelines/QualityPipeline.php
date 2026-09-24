<?php

namespace App\Discussion\Pipelines;

use App\Discussion\Selectors\JudgeSelector;
use App\Discussion\Stages\PersistMessage;
use App\Discussion\Stages\SelectSpeaker;
use App\Discussion\Stages\Speak;
use App\Discussion\Stages\Summarize;
use App\Discussion\Stages\ThinkAndPropose;

/**
 * Everyone drafts, a judge awards the floor. The winner then writes with the
 * Speak prompt and their draft as context: the length of a contribution is a
 * measured value and must come from the same source as in every other cell.
 */
class QualityPipeline implements TurnPipeline
{
    public function stages(): array
    {
        return [
            ThinkAndPropose::class,
            SelectSpeaker::with(JudgeSelector::class),
            Speak::class,
            PersistMessage::class,
            Summarize::class,
        ];
    }
}
