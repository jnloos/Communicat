<?php

namespace App\Discussion\Pipelines;

use App\Discussion\Selectors\JevSpeakerSelector;

/** OrchestratorPipeline with Jev picking the next speaker (see DecidesWithJev). */
class JevOrchestratorPipeline extends OrchestratorPipeline implements DeclaresDecisionModel
{
    use DecidesWithJev;

    protected function selector(): string
    {
        return JevSpeakerSelector::class;
    }
}
