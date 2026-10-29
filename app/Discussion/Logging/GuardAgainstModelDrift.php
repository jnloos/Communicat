<?php

namespace App\Discussion\Logging;

use App\Discussion\Agents\StudyAgent;
use App\Discussion\Support\ModelIdentity;
use App\Models\JobLog;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Events\StepCompleted;

/**
 * A gateway may answer with another model than the one asked for (a combo, a
 * fallback inside the gateway); the SDK's failover guard cannot see that. This
 * only makes noise and never stops the turn: the study reads
 * prompt_logs.model_served afterwards and decides what to exclude.
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

        $job = $agent->jobLogId === null ? null : JobLog::find($agent->jobLogId);

        Log::error('The model that answered differs from the model asked for; this call is not what the run configured.', [
            'project_id' => $job?->project_id,
            'turn_index' => $job?->turn_index,
            'expert_id' => $agent->expertId,
            'requested' => $agent->model->model,
            'served' => $served,
        ]);
    }
}
