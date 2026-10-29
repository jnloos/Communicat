<?php

namespace App\Discussion\Pipelines;

use App\Discussion\Jev\JevClient;
use App\Discussion\Values\JevDecides;
use App\Discussion\Values\JevDecisionModel;

/**
 * What the four Jev pipelines share. A Jev pipeline is its parent pipeline with
 * Jev (TypeSafe's fixed decision model, one pinned version) taking the model
 * decision instead of the team's model; the stages around the decision are the
 * parent's, and Jev is the same whichever team model writes the discussion. A
 * pipeline using this trait implements DeclaresDecisionModel.
 */
trait DecidesWithJev
{
    public function decisionModel(): JevDecisionModel
    {
        return JevDecisionModel::fromConfig($this->decides());
    }

    public function assertDecisionModelReady(): void
    {
        app(JevClient::class)->assertConfigured($this->decisionModel());
    }

    /** What Jev stands in for in this pipeline; a pipeline whose Jev gives bids says Bidder. */
    protected function decides(): JevDecides
    {
        return JevDecides::Selector;
    }
}
