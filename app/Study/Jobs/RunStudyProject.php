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
 * A turn that fails sets back only this run (backoff, and at the limits of RunHealth
 * the run is given up as `superseded`); a global error pauses the whole study through
 * the CircuitBreaker, and a run is never given up while the study is paused.
 */
class RunStudyProject implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const CONNECTION = 'study';

    /** Unlimited: a pause release or a pickup after retry_after is no failed attempt. */
    public int $tries = 0;

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

        $project = Project::findOrFail($this->projectId);

        // Catches a queue:retry of a run that was given up.
        if ($giveUp->isMarked($project)) {
            return;
        }

        // Not WithoutOverlapping: its dontRelease() would drop the successor if another worker
        // took it before this job left the middleware, and the chain would end silently.
        $delay = Cache::lock("study-run:{$this->projectId}", config('study.run.lock_seconds'))
            ->get(fn () => $this->turn($runner, $pause, $breaker, $giveUp, $project));

        // false: another job holds the run and queues its own successor. null: the chain ends.
        if (is_int($delay)) {
            self::dispatch($this->projectId)->delay($delay);
        }
    }

    /** @return int|null seconds until the successor, null for none */
    private function turn(TurnRunner $runner, PauseFlag $pause, CircuitBreaker $breaker, GiveUp $giveUp, Project $project): ?int
    {
        $this->closeAbandonedTurns();

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

        return $this->after($result, $pause, $breaker, $giveUp, $project);
    }

    private function after(TurnResult $result, PauseFlag $pause, CircuitBreaker $breaker, GiveUp $giveUp, Project $project): ?int
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
            $giveUp->mark($project);
            $this->fail(new RuntimeException("Run {$this->projectId} given up: {$health->failedInARow} failed and {$health->stalledInARow} silent turns in a row."));

            return null;
        }

        // A model failure says nothing about the next turn; an infrastructure one needs time.
        return $result->reason === JobStatus::Failed->value ? 0 : $health->backoffSeconds();
    }

    /**
     * A worker that died mid-turn left its job log running. The lock proves no turn of
     * this run is alive, so the row is dead: close it as interrupted (it counts as stalled).
     */
    private function closeAbandonedTurns(): void
    {
        JobLog::where('project_id', $this->projectId)
            ->where('status', JobStatus::Running)
            ->update(['status' => JobStatus::Interrupted, 'finished_at' => now(), 'error' => 'Worker stopped mid-turn']);
    }
}
