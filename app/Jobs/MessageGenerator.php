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
                JobLogged::dispatch($log->fresh());

                if (! empty($pipelineResult['stop'])) {
                    $continue = false;
                    ProjectJob::stopGenerating($project->id);

                    // Only a genuine floor hand-off asks a human for input.
                    // Technical stops (no candidates, no THINK output) used to
                    // fire this too, and with no resolved target the frontend
                    // fell back to prompting *every* viewer for input.
                    if (($pipelineResult['reason'] ?? null) === 'user_addressed') {
                        UserInputRequested::dispatch(
                            $project->id,
                            'user_addressed',
                            $pipelineResult['user_id'] ?? $project->user_id,
                        );
                    } else {
                        GenerationStopped::dispatch($project->id);
                    }
                }
            } catch (Exception $e) {
                Log::error(sprintf('%s: %s', $e->getMessage(), $e->getTraceAsString()));
                $failPayload = ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()];
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

            // Halt the loop if nobody has the discussion open anymore, so it can
            // never run unattended (no accidental generations). Decided BEFORE
            // announcing the turn — see the delay note below.
            if ($continue && ! ProjectJob::hasViewers($project->id)) {
                $continue = false;
                ProjectJob::stopGenerating($project->id);
                GenerationStopped::dispatch($project->id);
            }

            // The shared flag may have been cleared during this turn (another
            // client pressed stop).
            $willContinue = $continue && ProjectJob::isGenerating($project->id);

            // This delay doubles as the frontend's "next contribution in Xs"
            // countdown, so it must reflect whether a follow-up is *actually*
            // queued. Deriving it from $continue alone announced — and animated —
            // a turn that never came.
            $nextTurnDelay = ($willContinue && $latestMessage !== null)
                ? ReadingPause::secondsFor($latestMessage->content)
                : 0;

            MessageGenerated::dispatch(
                $project->id,
                $latestMessage?->id,
                $nextTurnDelay,
            );

            // Server-driven loop: the next job is queued with a reading pause so
            // the message is visible first.
            if ($willContinue) {
                $dispatch = static::dispatch($project->id);

                if ($nextTurnDelay > 0) {
                    $dispatch->delay(now()->addSeconds($nextTurnDelay));
                }
            }
        });
    }
}
