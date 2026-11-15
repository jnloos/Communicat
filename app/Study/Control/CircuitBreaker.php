<?php

namespace App\Study\Control;

use App\Discussion\AbortsRun;
use App\Models\JobLog;
use App\Study\Events\BreakerTripped;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Pauses the whole study on a global error before it burns a wave of runs: at once on
 * an AbortsRun or a fatal error text, otherwise after a streak of turns without a word
 * over all runs (the safety net; a rate over a window would trip on normal failure
 * rates). Only study:resume lifts it: a pause that ended by itself would be the same
 * empty wave, unwatched.
 */
class CircuitBreaker
{
    public function __construct(
        private readonly PauseFlag $pause,
        private readonly BreakerStreak $streak,
        private readonly FatalSignal $fatal,
    ) {}

    public function turnSpoken(): void
    {
        $this->streak->reset();
    }

    /** A turn without a word; $log is null when it failed before a job log existed. */
    public function turnFailed(int $projectId, ?JobLog $log): void
    {
        $signal = $log?->error === null ? null : $this->fatal->matches($log->error);

        if ($signal !== null) {
            $this->trip($signal, $log->error, $projectId);
        }

        $this->countTowardsStreak($projectId);
    }

    /** AbortsRun means no later turn gets past it, and the configuration is the same in every run. */
    public function aborted(int $projectId, AbortsRun $e): void
    {
        $this->trip('abort', class_basename($e).': '.$e->getMessage(), $projectId);
    }

    /** A bug, not a model answer: it still counts, since a broken deploy fails every run alike. */
    public function unexpected(int $projectId): void
    {
        $this->countTowardsStreak($projectId);
    }

    private function countTowardsStreak(int $projectId): void
    {
        $count = $this->streak->increment();

        if ($count >= config('study.breaker.streak_limit')) {
            $this->trip('streak', "{$count} turns in a row without a word, over all runs", $projectId);
        }
    }

    private function trip(string $signal, string $reason, ?int $projectId): void
    {
        // Only the call that set the pause reports it, so six workers tripping at once alert once.
        if (! $this->pause->pause($reason, $signal, PauseSource::Breaker)) {
            return;
        }

        Log::alert("Study paused by the circuit breaker ({$signal}): {$reason}", ['project_id' => $projectId]);
        BreakerTripped::dispatch($signal, $reason, $projectId, CarbonImmutable::now());
    }
}
