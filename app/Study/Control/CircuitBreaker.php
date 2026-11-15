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

    /**
     * Lifts the pause and resets the streak: the old one would trip again on the first failed turn.
     *
     * @throws \RuntimeException when the pause file stays, see PauseFlag::resume()
     */
    public function resume(): void
    {
        $this->pause->resume();
        $this->streak->reset();
    }

    public function turnSpoken(): void
    {
        $this->streak->reset();
    }

    /**
     * A turn that stopped on a failure; $log is null when it failed before a job log existed.
     * One that spoke before it failed (words set, the counting rule) is spoken for the streak.
     */
    public function turnFailed(int $projectId, ?JobLog $log): void
    {
        $signal = $log?->error === null ? null : $this->fatal->matches($log->error);

        if ($signal !== null) {
            $this->trip($signal, $log->error, $projectId);
        }

        if ($log?->words !== null) {
            $this->turnSpoken();

            return;
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

    /** A pause file that cannot be written throws on through the caller: a breaker that cannot pause must not look fine. */
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
