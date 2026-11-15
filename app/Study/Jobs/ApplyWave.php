<?php

namespace App\Study\Jobs;

use App\Study\Models\StudyWave;
use App\Study\Planning\WavePlanning;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Study-only, removable: see app/Study/README.md.
 *
 * Writes a wave the planning page confirmed: 3,072 runs take longer than a web
 * request. On the app's default queue, not `study`: a paused study worker or
 * thousands of waiting turn jobs must not hold it up. Writing is idempotent, so
 * a second job for the same wave is dropped and a retry continues.
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
        $planning->apply(StudyWave::findOrFail($this->waveId));
    }
}
