<?php

namespace App\Discussion\Pipelines;

use App\Discussion\Selectors\JevJudgeSelector;

/** QualityPipeline with Jev judging the drafts (see DecidesWithJev). */
class JevQualityPipeline extends QualityPipeline implements DeclaresDecisionModel
{
    use DecidesWithJev;

    protected function selector(): string
    {
        return JevJudgeSelector::class;
    }
}
