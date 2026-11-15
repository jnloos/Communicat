<?php

namespace App\Discussion\Logging;

use App\Discussion\Agents\StudyAgent;
use App\Discussion\ModelDrifted;
use App\Discussion\Support\ModelIdentity;
use Laravel\Ai\Events\StepCompleted;

/**
 * A gateway may answer with another model than the one asked for (a combo, a
 * fallback inside the gateway); the SDK's failover guard cannot see that. It
 * throws ModelDrifted, which ends the run (AbortsRun). Registered after
 * RecordPromptLog, so the call is on record with its model_served first. The SDK
 * dispatches StepCompleted outside its own catch and rethrows from prompt(), so
 * the exception reaches TurnRunner (GuardAgainstModelDriftTest drives that path).
 *
 * Providers whose responses only echo the requested id are skipped, since the
 * comparison would hold by construction.
 */
class GuardAgainstModelDrift
{
    public function handle(StepCompleted $event): void
    {
        $agent = $event->agent;

        if (! $agent instanceof StudyAgent || ! $agent->model->reportsServedModel()) {
            return;
        }

        $served = (string) $event->response->meta->model;

        if (ModelIdentity::matches($agent->model->model, $served)) {
            return;
        }

        throw new ModelDrifted($agent->model->model, $served);
    }
}
