<?php

namespace App\Services\Study\Watch;

use App\Jobs\RunStudyProject;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Cache;

/**
 * "Some study worker is alive", written from the queue loop. Looping fires on every round,
 * also when the queue is empty or a job backs off, where a signal written by jobs would look
 * like a dead worker. One shared signal, last writer wins; at most every 15 s per process.
 */
class WorkerHeartbeat
{
    public const CACHE_KEY = 'study.worker.seen';

    private const EVERY_SECONDS = 15;

    private int $lastWrite = 0;

    public function handle(Looping $event): void
    {
        if ($event->connectionName !== RunStudyProject::CONNECTION || now()->timestamp - $this->lastWrite < self::EVERY_SECONDS) {
            return;
        }

        $this->lastWrite = now()->timestamp;
        Cache::put(self::CACHE_KEY, $this->lastWrite, 3600);
    }
}
