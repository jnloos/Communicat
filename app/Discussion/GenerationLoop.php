<?php

namespace App\Discussion;

use Illuminate\Support\Facades\Cache;

/**
 * Shared state of the self-perpetuating generation loop, kept in the cache so
 * every user and every queue worker sees the same thing. Only the interactive
 * UI loop needs it; a headless experiment run drives TurnRunner directly.
 */
class GenerationLoop
{
    /** Must outlast the longest possible turn (MessageGenerator::$timeout), or turns could overlap. */
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

    public function isTurnRunning(int $projectId): bool
    {
        $lock = Cache::lock($this->lockKey($projectId));

        if ($lock->get() === false) {
            return true;
        }

        $lock->release();

        return false;
    }

    /** Runs $callback unless another turn of this project holds the lock. */
    public function withLock(int $projectId, callable $callback): void
    {
        $lock = Cache::lock($this->lockKey($projectId), self::LOCK_SECONDS);

        if ($lock->get() === false) {
            return;
        }

        try {
            $callback();
        } finally {
            $lock->release();
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

    private function lockKey(int $projectId): string
    {
        return "project_{$projectId}_lock";
    }
}
