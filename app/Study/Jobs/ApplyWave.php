<?php

namespace App\Study\Jobs;

use App\Study\Models\StudyWave;
use App\Study\Planning\WavePlanning;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Study-only, removable: see app/Study/README.md.
 *
 * Writes a wave the planning page confirmed: 3,072 runs take longer than a web
 * request. On the app's default queue, not `study`: a paused study worker or
 * thousands of waiting turn jobs must not hold it up. Writing is idempotent, so
 * a second job for the same wave is dropped and a retry continues.
 *
 * shortcut: $timeout (3600) can exceed the default queue's retry_after, so a second worker could pull
 * a still running job; harmless, as the writing is idempotent and WithoutOverlapping drops it. Raise
 * the default connection's retry_after above the timeout once more than one default worker runs.
 */
class ApplyWave implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 3600;

    public function __construct(public int $waveId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping("study-wave-{$this->waveId}"))->dontRelease()->expireAfter($this->timeout + 60)];
    }

    public function handle(WavePlanning $planning): void
    {
        $wave = StudyWave::findOrFail($this->waveId);
        $wave->update(['failed_reason' => null, 'failed_at' => null]);

        $planning->apply($wave);
    }

    /** The page reads this; without it a failed wave would look like one still being written. */
    public function failed(Throwable $exception): void
    {
        StudyWave::whereKey($this->waveId)->update(['failed_reason' => $exception->getMessage(), 'failed_at' => now()]);
    }
}
