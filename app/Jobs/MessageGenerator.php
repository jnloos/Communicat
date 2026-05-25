<?php

namespace App\Jobs;

use App\Events\GenerationStopped;
use App\Events\JobLogged;
use App\Events\MessageGenerated;
use App\Events\UserInputRequested;
use App\Jobs\Dependencies\ProjectJob;
use App\Models\JobLog;
use App\Models\Project;
use App\Services\Clients\OpenAIClient;
use App\Services\PromptingPipeline\DiscussionPipeline;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class MessageGenerator extends ProjectJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 280;

    public function __construct(int $projectId) {
        $this->setProject($projectId);
    }

    public function handle(): void {
        $this->withProjectLock(function (Project $project) {
            $log = JobLog::create([
                'job_class'  => static::class,
                'project_id' => $project->id,
                'status'     => 'running',
                'started_at' => now(),
            ]);
            JobLogged::dispatch($log);

            OpenAIClient::bindJobLog($log->id);

            // Whether the discussion loop should keep running after this turn.
            // Any stop signal (user input requested, hard failure) clears the
            // shared flag so no further turn is dispatched.
            $continue = true;

            try {
                $pipelineResult = (new DiscussionPipeline($project, $log->id))->run();
                $log->update(['status' => 'success', 'finished_at' => now()]);
                JobLogged::dispatch($log->fresh());

                if (!empty($pipelineResult['stop'])) {
                    $continue = false;
                    ProjectJob::stopGenerating($project->id);
                    UserInputRequested::dispatch(
                        $project->id,
                        $pipelineResult['reason'] ?? 'stop',
                        $pipelineResult['user_id'] ?? null,
                    );
                }
            } catch (Exception $e) {
                Log::error(sprintf("%s: %s", $e->getMessage(), $e->getTraceAsString()));
                $log->update([
                    'status'      => 'failed',
                    'finished_at' => now(),
                    'payload'     => ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()],
                ]);
                JobLogged::dispatch($log->fresh());

                // A failed turn ends the loop and tells every client to flip
                // back to the "start" state.
                $continue = false;
                ProjectJob::stopGenerating($project->id);
                GenerationStopped::dispatch($project->id);
            } finally {
                OpenAIClient::bindJobLog(null);
            }

            $latestMessageId = $project->messages()
                ->whereNotNull('expert_id')
                ->latest('id')
                ->value('id');

            MessageGenerated::dispatch($project->id, $latestMessageId);

            // Halt the loop if nobody has the discussion open anymore, so it can
            // never run unattended (no accidental generations).
            if ($continue && !ProjectJob::hasViewers($project->id)) {
                $continue = false;
                ProjectJob::stopGenerating($project->id);
                GenerationStopped::dispatch($project->id);
            }

            // Server-driven loop: keep going only while the shared flag is still
            // set (a user may have pressed stop during this turn). The next job
            // queues here and acquires the project lock once this one releases.
            if ($continue && ProjectJob::isGenerating($project->id)) {
                static::dispatch($project->id);
            }
        });
    }
}
