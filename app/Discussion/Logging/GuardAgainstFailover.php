<?php

namespace App\Discussion\Logging;

use Illuminate\Support\Facades\Log;

/**
 * The SDK can silently switch providers when a call fails. For this study that
 * would break a run: the model family per run must stay homogeneous, and a
 * silent switch would not show up anywhere in the measurement. Nothing here
 * configures failover — this only makes noise if it ever happens.
 */
class GuardAgainstFailover
{
    public function handle(object $event): void
    {
        Log::error('An AI provider failover happened; this run is no longer homogeneous. Event: '.$event::class);
    }
}
