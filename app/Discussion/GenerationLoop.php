<?php

namespace App\Discussion;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Shared state of the self-perpetuating generation loop, kept in the cache so
 * every user and every queue worker sees the same thing. Only the interactive
 * UI loop needs it; a headless experiment run drives TurnRunner directly.
 */
class GenerationLoop
{
    /**
     * Must outlast the longest possible turn (MessageGenerator::$timeout), or turns
     * could overlap. Also the life of the running flag, should a worker die mid-turn.
     */
    private const LOCK_SECONDS = 300;

    /** Generous enough to survive background-tab poll throttling (~60s). */
    private const VIEWER_SECONDS = 90;

    /** Safety net so a crashed loop can never stay switched on. */
    private const GENERATING_MINUTES = 30;

    public function start(int $projectId): void
    {
        Cache::put($this->generatingKey($projectId), true, now()->addMinutes(self::GENERATING_MINUTES));
    }

    public function stop(int $projectId): void
    {
        Cache::forget($this->generatingKey($projectId));
    }

    public function isGenerating(int $projectId): bool
    {
        return (bool) Cache::get($this->generatingKey($projectId), false);
    }

    /** Heartbeat of an open chat view; the loop halts once nobody is watching. */
    public function markViewing(int $projectId): void
    {
        Cache::put($this->viewersKey($projectId), true, now()->addSeconds(self::VIEWER_SECONDS));
    }

    public function hasViewers(int $projectId): bool
    {
        return (bool) Cache::get($this->viewersKey($projectId), false);
    }

    /**
     * Whether a turn of this project is running right now. Reads a flag that
     * withLock() raises, so asking never takes the lock itself -- a poll that
     * held it even for a moment could make a queued turn skip its run.
     */
    public function isTurnRunning(int $projectId): bool
    {
        return (bool) Cache::get($this->runningKey($projectId), false);
    }

    /** Runs $callback unless another turn of this project holds the lock. */
    public function withLock(int $projectId, callable $callback): void
    {
        $ran = Cache::lock($this->lockKey($projectId), self::LOCK_SECONDS)
            ->get(function () use ($projectId, $callback): bool {
                Cache::put($this->runningKey($projectId), true, self::LOCK_SECONDS);

                try {
                    $callback();
                } finally {
                    Cache::forget($this->runningKey($projectId));
                }

                return true;
            });

        if ($ran !== true) {
            Log::info("Skipped a turn of project {$projectId}: another turn holds the lock.");
        }
    }

    private function generatingKey(int $projectId): string
    {
        return "project_{$projectId}_generating";
    }

    private function viewersKey(int $projectId): string
    {
        return "project_{$projectId}_viewers";
    }

    private function runningKey(int $projectId): string
    {
        return "project_{$projectId}_turn_running";
    }

    private function lockKey(int $projectId): string
    {
        return "project_{$projectId}_lock";
    }
}
