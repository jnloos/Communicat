<?php

namespace App\Study\Jobs;

use App\Discussion\AbortsRun;
use App\Discussion\TransientFailure;
use App\Discussion\TurnRunner;
use App\Discussion\Values\JobStatus;
use App\Discussion\Values\TurnResult;
use App\Models\JobLog;
use App\Models\Project;
use App\Study\Control\CircuitBreaker;
use App\Study\Control\GiveUp;
use App\Study\Control\PauseFlag;
use App\Study\Control\RunHealth;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * Runs exactly one turn of a study run, headless, on the `study` queue, then queues
 * the run's next turn itself. One turn per job, so a stop, a restart or a pause waits
 * at most one turn. The batch counterpart of `project:run`: no GenerationLoop.
 *
 * A turn that fails sets back only this run (backoff, and at the limits of RunHealth,
 * failed or abandoned turns in a row, the run is given up as `superseded`; an
 * infrastructure interruption never gives it up); a global error pauses the whole study
 * through the CircuitBreaker, and a run is never given up while the study is paused.
 */
class RunStudyProject implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const CONNECTION = 'study';

    /** Unlimited: a pause release or a pickup after retry_after is no failed attempt. */
    public int $tries = 0;

    /** Unlimited tries, so an exception outside the turn retries for ever: this keeps it from spinning. */
    public array $backoff = [10, 60, 300];

    /** A net against a unique lock orphaned by queue:clear. */
    public int $uniqueFor = 3600;

    public int $timeout;

    public function __construct(public int $projectId)
    {
        $this->timeout = config('study.run.turn_timeout');
        $this->onConnection(self::CONNECTION)->onQueue(self::CONNECTION);
    }

    /** study:queue may run twice without starting a second chain for the same run. */
    public function uniqueId(): string
    {
        return (string) $this->projectId;
    }

    public function handle(TurnRunner $runner, PauseFlag $pause, CircuitBreaker $breaker, GiveUp $giveUp): void
    {
        // Normally the Looping hook keeps a paused worker from taking this job at all.
        if ($pause->active()) {
            $this->release(config('study.run.pause_poll_seconds'));

            return;
        }

        $project = Project::find($this->projectId);

        // A deleted run, or a queue:retry of one that was given up: the chain ends here.
        if ($project === null || $giveUp->isMarked($project)) {
            return;
        }

        // Not WithoutOverlapping: its dontRelease() would drop the successor if another worker
        // took it before this job left the middleware, and the chain would end silently.
        $lock = Cache::lock("study-run:{$this->projectId}", config('study.run.lock_seconds'));

        // Another job holds the run and queues its own successor.
        if (! $lock->get()) {
            return;
        }

        try {
            $delay = $this->nextTurnDelay($runner, $pause, $breaker, $giveUp, $project);
        } finally {
            $lock->release();
        }

        if ($delay !== null) {
            self::dispatch($this->projectId)->delay($delay);
        }
    }

    /**
     * Runs one turn, unless the run is given up first.
     *
     * @return int|null seconds until the successor, null for none
     */
    private function nextTurnDelay(TurnRunner $runner, PauseFlag $pause, CircuitBreaker $breaker, GiveUp $giveUp, Project $project): ?int
    {
        $this->closeAbandonedTurns();
        $health = RunHealth::of($this->projectId);

        // A turn that kills its worker or outlives the timeout every time would loop for ever.
        if ($health->abandonedTooOften() && ! $pause->active()) {
            return $this->giveUp($giveUp, $project, $health);
        }

        try {
            $result = $runner->run($project);
        } catch (AbortsRun $e) {
            $breaker->aborted($this->projectId, $e);

            return config('study.run.pause_poll_seconds');
        } catch (Throwable $e) {
            // Thrown outside the turn's own try, e.g. a broadcast or a locked database while
            // opening the job log.
            if (TransientFailure::is($e)) {
                $breaker->turnFailed($this->projectId, null);

                return RunHealth::of($this->projectId)->backoffSeconds();
            }

            // A bug: the run's chain ends in failed_jobs, study:queue restores it after the fix.
            $breaker->unexpected($this->projectId);
            $this->fail($e);

            throw $e;
        }

        return $this->delayAfter($result, $pause, $breaker, $giveUp, $project);
    }

    /** @return int|null seconds until the successor, null for none */
    private function delayAfter(TurnResult $result, PauseFlag $pause, CircuitBreaker $breaker, GiveUp $giveUp, Project $project): ?int
    {
        if (! $result->stop) {
            $breaker->turnSpoken();

            return 0;
        }

        if ($result->reason === 'turn_budget') {
            return null;
        }

        if (! in_array($result->reason, [JobStatus::Failed->value, JobStatus::Interrupted->value, 'jev_outage'], true)) {
            $this->fail(new RuntimeException("Project {$this->projectId} stopped: {$result->reason}"));

            return null;
        }

        $breaker->turnFailed($this->projectId, JobLog::where('project_id', $this->projectId)->latest('id')->first());
        $health = RunHealth::of($this->projectId);

        // A run caught in an outage is a victim, not waste: never given up while paused,
        // which includes the breaker having tripped in this very turn.
        if ($health->exhausted() && ! $pause->active()) {
            return $this->giveUp($giveUp, $project, $health);
        }

        // A model failure says nothing about the next turn; an infrastructure one needs time.
        return $result->reason === JobStatus::Failed->value ? 0 : $health->backoffSeconds();
    }

    /** @return null no successor: the chain ends in failed_jobs, the tag keeps study:queue away */
    private function giveUp(GiveUp $giveUp, Project $project, RunHealth $health): null
    {
        $giveUp->mark($project);
        $this->fail(new RuntimeException("Run {$this->projectId} given up: {$health->failedInARow} failed and {$health->abandonedInARow} abandoned turns in a row."));

        return null;
    }

    /**
     * A worker that died mid-turn, or a turn past its timeout, left its job log running.
     * The lock proves no turn of this run is alive, so the row is dead: close it as
     * interrupted, marked abandoned so RunHealth can give up a run that does it again and again.
     */
    private function closeAbandonedTurns(): void
    {
        JobLog::where('project_id', $this->projectId)
            ->where('status', JobStatus::Running)
            ->update(['status' => JobStatus::Interrupted, 'finished_at' => now(), 'error' => RunHealth::ABANDONED]);
    }
}
