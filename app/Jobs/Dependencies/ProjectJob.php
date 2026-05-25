<?php
namespace App\Jobs\Dependencies;

use App\Models\Project;
use Illuminate\Support\Facades\Cache;

class ProjectJob
{
    protected static int $lockTTL = 30;

    protected Project $project;

    public function setProject(int $projectId): void {
        $this->project = Project::findOrFail($projectId);
    }

    public static function lockName(int $projectId): string {
        return "project_{$projectId}_lock";
    }

    // -------------------------------------------------------------------------
    // Generation loop flag (shared across users/servers via the cache)
    //
    // The discussion runs as a self-perpetuating chain of MessageGenerator jobs.
    // This flag — not a per-browser Livewire property — is the single source of
    // truth for "keep generating". Setting it starts the loop; clearing it stops
    // the loop authoritatively for every connected user after the current turn.
    // -------------------------------------------------------------------------

    public static function generatingKey(int $projectId): string {
        return "project_{$projectId}_generating";
    }

    public static function startGenerating(int $projectId): void {
        // TTL is a safety net so a crashed loop can never get stuck "on".
        Cache::put(static::generatingKey($projectId), true, now()->addMinutes(30));
    }

    public static function stopGenerating(int $projectId): void {
        Cache::forget(static::generatingKey($projectId));
    }

    public static function isGenerating(int $projectId): bool {
        return (bool) Cache::get(static::generatingKey($projectId), false);
    }

    // -------------------------------------------------------------------------
    // Viewer presence (heartbeat)
    //
    // Open project-chat views refresh this key on a poll. The generation loop
    // only continues while at least one viewer is present — closing every tab
    // lets the key expire, which halts the loop and prevents accidental,
    // unattended generation. The TTL is generous enough to survive background-
    // tab poll throttling (~60s) without falsely dropping a viewer.
    // -------------------------------------------------------------------------

    protected static int $viewerTTL = 90;

    public static function viewersKey(int $projectId): string {
        return "project_{$projectId}_viewers";
    }

    public static function markViewing(int $projectId): void {
        Cache::put(static::viewersKey($projectId), true, now()->addSeconds(static::$viewerTTL));
    }

    public static function hasViewers(int $projectId): bool {
        return (bool) Cache::get(static::viewersKey($projectId), false);
    }

    public static function isRunningFor(int $projectId): bool {
        $project = Project::findOrFail($projectId);
        $lock = Cache::lock(static::lockName($project->id));
        $token = $lock->get();

        if ($token === false) {
            return true;
        }

        $lock->release();
        return false;
    }

    protected function withProjectLock(callable $callback): void {
        $lock = Cache::lock(static::lockName($this->project->id), seconds: self::$lockTTL);
        $token = $lock->get();

        if ($token === false) {
            return;
        }

        try {
            $callback($this->project);
        } finally {
            $lock->release();
        }
    }
}
