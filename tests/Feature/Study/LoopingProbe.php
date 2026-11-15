<?php

namespace Tests\Feature\Study;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** A job that only counts that a worker ran it. */
class LoopingProbe implements ShouldQueue
{
    use Dispatchable, Queueable;

    public static int $ran = 0;

    public function handle(): void
    {
        self::$ran++;
    }
}
