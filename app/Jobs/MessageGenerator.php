<?php

namespace App\Jobs;

use App\Events\GenerationStopped;
use App\Events\JobLogged;
use App\Events\MessageGenerated;
use App\Events\UserInputRequested;
use App\Jobs\Dependencies\ProjectJob;
use App\Models\JobLog;
use App\Models\Message;
use App\Models\Project;
use App\Services\Clients\OpenAIClient;
use App\Services\PromptingPipeline\DiscussionPipeline;
use App\Services\PromptingPipeline\Support\ReadingPause;
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

    public function __construct(int $projectId)
    {
        $this->setProject($projectId);
    }

    public function handle(): void
    {
        // A delayed follow-up may have been queued before the user pressed stop.
        if (! ProjectJob::isGenerating($this->project->id)) {
            return;
        }

        $this->withProjectLock(function (Project $project) {
            if (! ProjectJob::isGenerating($project->id)) {
                return;
            }

            $log = JobLog::create([
                'job_class' => static::class,
                'project_id' => $project->id,
                'status' => 'running',
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
                // #region agent log
                file_put_contents(base_path('.cursor/debug-c0b61f.log'), json_encode([
                    'sessionId' => 'c0b61f',
                    'runId' => 'post-fix',
                    'hypothesisId' => 'A',
                    'location' => 'MessageGenerator.php:success',
                    'message' => 'Pipeline completed successfully',
                    'data' => [
                        'project_id' => $project->id,
                        'job_log_id' => $log->id,
                        'stop' => ! empty($pipelineResult['stop']),
                    ],
                    'timestamp' => (int) (microtime(true) * 1000),
                ])."\n", FILE_APPEND);
                // #endregion
                JobLogged::dispatch($log->fresh());

                if (! empty($pipelineResult['stop'])) {
                    $continue = false;
                    ProjectJob::stopGenerating($project->id);
                    UserInputRequested::dispatch(
                        $project->id,
                        $pipelineResult['reason'] ?? 'stop',
                        $pipelineResult['user_id'] ?? null,
                    );
                }
            } catch (Exception $e) {
                Log::error(sprintf('%s: %s', $e->getMessage(), $e->getTraceAsString()));
                $failPayload = ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()];
                // #region agent log
                file_put_contents(base_path('.cursor/debug-c0b61f.log'), json_encode([
                    'sessionId' => 'c0b61f',
                    'runId' => 'post-fix',
                    'hypothesisId' => 'B',
                    'location' => 'MessageGenerator.php:catch',
                    'message' => 'Pipeline failed; measuring JobLog failure payload',
                    'data' => [
                        'error' => $e->getMessage(),
                        'payload_bytes' => strlen(json_encode($failPayload)),
                        'trace_bytes' => strlen($e->getTraceAsString()),
                        'reverb_max_message_size' => (int) config('reverb.apps.apps.0.max_message_size', 10000),
                    ],
                    'timestamp' => (int) (microtime(true) * 1000),
                ])."\n", FILE_APPEND);
                // #endregion
                $log->update([
                    'status' => 'failed',
                    'finished_at' => now(),
                    'payload' => $failPayload,
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

            /** @var Message|null $latestMessage */
            $latestMessage = $project->messages()
                ->whereNotNull('expert_id')
                ->latest('id')
                ->first();

            $nextTurnDelay = 0;
            if ($continue && $latestMessage !== null) {
                $nextTurnDelay = ReadingPause::secondsFor($latestMessage->content);
            }

            MessageGenerated::dispatch(
                $project->id,
                $latestMessage?->id,
                $nextTurnDelay,
            );

            // Halt the loop if nobody has the discussion open anymore, so it can
            // never run unattended (no accidental generations).
            if ($continue && ! ProjectJob::hasViewers($project->id)) {
                $continue = false;
                ProjectJob::stopGenerating($project->id);
                GenerationStopped::dispatch($project->id);
            }

            // Server-driven loop: keep going only while the shared flag is still
            // set (a user may have pressed stop during this turn). The next job
            // is queued with a reading pause so the message is visible first.
            if ($continue && ProjectJob::isGenerating($project->id)) {
                $dispatch = static::dispatch($project->id);

                if ($nextTurnDelay > 0) {
                    $dispatch->delay(now()->addSeconds($nextTurnDelay));
                }
            }
        });
    }
}
