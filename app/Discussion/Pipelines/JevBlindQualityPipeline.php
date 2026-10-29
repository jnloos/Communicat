<?php

namespace App\Discussion\Pipelines;

use App\Discussion\Selectors\JevBlindJudgeSelector;

/** BlindQualityPipeline with Jev judging the anonymous drafts (see DecidesWithJev). */
class JevBlindQualityPipeline extends BlindQualityPipeline implements DeclaresDecisionModel
{
    use DecidesWithJev;

    protected function selector(): string
    {
        return JevBlindJudgeSelector::class;
    }
}
