<?php

namespace App\Pipeline\Candidates;

use App\Models\Expert;
use App\Pipeline\TurnContext;

interface CandidateStrategy
{
    /**
     * Build the candidate pool for the current turn.
     *
     * @return Expert[]
     */
    public function select(TurnContext $ctx): array;
}
