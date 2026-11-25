<?php

namespace App\Services\Study\Control;

use App\Jobs\RunStudyProject;
use Illuminate\Support\Facades\DB;

/**
 * Thesis study, see docs/study.md.
 *
 * Which runs really have a job row on the study queue, waiting, delayed or
 * reserved. A unique lock alone does not prove that: a job failed while it was
 * being reserved keeps its lock but has no row, and its chain is gone.
 *
 * shortcut: reads the database queue's table; upgrade if the study queue moves to Redis.
 */
final class QueuedRuns
{
    /** @return array<int, true> project id => true */
    public function ids(): array
    {
        $config = config('queue.connections.'.RunStudyProject::CONNECTION);

        return DB::connection($config['connection'] ?? null)->table($config['table'])
            ->where('queue', $config['queue'])
            ->pluck('payload')
            ->map(fn (string $payload) => self::projectIdIn($payload))
            ->filter()
            ->mapWithKeys(fn (int $id) => [$id => true])
            ->all();
    }

    /** The project id inside a serialised RunStudyProject payload. */
    public static function projectIdIn(string $payload): ?int
    {
        return preg_match('/projectId\\\\?";i:(\d+);/', $payload, $match) ? (int) $match[1] : null;
    }
}
