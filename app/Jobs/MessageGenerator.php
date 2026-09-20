<?php

namespace App\Jobs;

use App\Discussion\GenerationLoop;
use App\Discussion\Support\ReadingPause;
use App\Discussion\TurnRunner;
use App\Events\GenerationStopped;
use App\Events\MessageGenerated;
use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The interactive UI loop: runs one turn, then queues itself again after a
 * reading pause. Everything about the turn itself lives in TurnRunner.
 */
class MessageGenerator implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 280;

    public function __construct(public int $projectId) {}

    public function handle(GenerationLoop $loop, TurnRunner $runner): void
    {
        // A delayed follow-up may have been queued before the user pressed stop.
        if (! $loop->isGenerating($this->projectId)) {
            return;
        }

        $loop->withLock($this->projectId, function () use ($loop, $runner) {
            if (! $loop->isGenerating($this->projectId)) {
                return;
            }

            $project = Project::findOrFail($this->projectId);
            $continue = ! $runner->run($project)->stop;

            $latest = $project->messages()->whereNotNull('expert_id')->latest('id')->first();
            $pause = $continue && $latest !== null ? ReadingPause::secondsFor($latest->content) : 0;

            MessageGenerated::dispatch($project->id, $latest?->id, $pause);

            // Never run unattended: stop once nobody has the discussion open.
            if (! $continue || ! $loop->hasViewers($project->id)) {
                $loop->stop($project->id);
                GenerationStopped::dispatch($project->id);

                return;
            }

            // A user may have pressed stop during this turn.
            if ($loop->isGenerating($project->id)) {
                $next = static::dispatch($project->id);

                if ($pause > 0) {
                    $next->delay(now()->addSeconds($pause));
                }
            }
        });
    }
}
