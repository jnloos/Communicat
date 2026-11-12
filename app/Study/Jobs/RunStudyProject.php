<?php

namespace App\Study\Jobs;

use App\Discussion\TransientFailure;
use App\Discussion\TurnRunner;
use App\Discussion\Values\JobStatus;
use App\Models\JobLog;
use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use RuntimeException;
use Throwable;

/**
 * Drives one study run to its turn budget, headless, on the `study` queue.
 *
 * The batch counterpart of `project:run`: no GenerationLoop, no ReadingPause.
 * Safe to dispatch twice and to restart: TurnRunner continues after the last
 * spoken turn, and a second job for a run already in progress is dropped.
 *
 * An infrastructure error (TransientFailure) puts the job back with a growing
 * backoff instead of burning the remaining turns; a model failure is a failed
 * turn and the run carries on, until MAX_FAILED_IN_A_ROW say it never will.
 */
class RunStudyProject implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const CONNECTION = 'study';

    public const MAX_FAILED_IN_A_ROW = 3;

    /** 30 turns take about 10 minutes; the study connection's retry_after stays above this. */
    public int $timeout = 3600;

    /** Every release counts as an attempt: about two hours of backoff before the job gives up. */
    public int $tries = 12;

    /** An exception that is not transient (AbortsRun, a bug) fails the job at once. */
    public int $maxExceptions = 1;

    public function __construct(public int $projectId)
    {
        $this->onConnection(self::CONNECTION)->onQueue(self::CONNECTION);
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->projectId))->dontRelease()->expireAfter($this->timeout + 60)];
    }

    public function handle(TurnRunner $runner): void
    {
        $this->closeAbandonedTurns();
        $failedInARow = 0;

        while (true) {
            try {
                $result = $runner->run(Project::findOrFail($this->projectId));
            } catch (Throwable $e) {
                // Thrown outside the turn's own try, e.g. a broadcast or a locked database
                // while opening the job log.
                if (! TransientFailure::is($e)) {
                    throw $e;
                }

                $this->backOff();

                return;
            }

            if (! $result->stop) {
                $failedInARow = 0;

                continue;
            }

            // jev_outage too: the guard halts again at once while Jev is still down.
            if (in_array($result->reason, [JobStatus::Interrupted->value, 'jev_outage'], true)) {
                $this->backOff();

                return;
            }

            if ($result->reason === JobStatus::Failed->value && ++$failedInARow < self::MAX_FAILED_IN_A_ROW) {
                continue;
            }

            if ($result->reason !== 'turn_budget') {
                $this->fail(new RuntimeException("Project {$this->projectId} stopped: {$result->reason}"));
            }

            return;
        }
    }

    private function backOff(): void
    {
        $this->release(min(60 * 2 ** ($this->attempts() - 1), 900));
    }

    /**
     * A worker that died mid-turn left its job log running. No other study job runs
     * this project (WithoutOverlapping), so the row is dead: close it as interrupted.
     */
    private function closeAbandonedTurns(): void
    {
        JobLog::where('project_id', $this->projectId)
            ->where('status', JobStatus::Running)
            ->update(['status' => JobStatus::Interrupted, 'finished_at' => now(), 'error' => 'Worker stopped mid-turn']);
    }
}
