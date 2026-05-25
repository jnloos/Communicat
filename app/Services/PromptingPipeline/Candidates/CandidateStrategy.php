<?php

namespace App\Services\PromptingPipeline\Candidates;

use App\Models\Expert;
use App\Services\PromptingPipeline\Data\TurnContext;

interface CandidateStrategy
{
    /**
     * Build the candidate pool for the current turn.
     *
     * @return Expert[]
     */
    public function select(TurnContext $ctx): array;
}
