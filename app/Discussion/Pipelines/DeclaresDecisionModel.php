<?php

namespace App\Discussion\Pipelines;

use App\Discussion\AbortsRun;
use App\Discussion\Values\JevDecisionModel;

/**
 * A pipeline whose decision (who speaks, or what each agent bids) is taken by a
 * fixed model that is not the team's. TurnRunner freezes what it declares into
 * run_config.decision_model on a run's first turn, so the decision maker of a
 * cell stays on record without anything asking for a pipeline's name, and asks it
 * to confirm the decision maker is reachable before the first turn is logged.
 */
interface DeclaresDecisionModel
{
    /** What this pipeline would use right now (the live setting; a started run reads its frozen copy). */
    public function decisionModel(): JevDecisionModel;

    /** @throws AbortsRun when the decision maker cannot be used at all (no key) */
    public function assertDecisionModelReady(): void;
}
