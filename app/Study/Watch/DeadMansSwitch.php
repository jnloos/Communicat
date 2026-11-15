<?php

namespace App\Study\Watch;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The external "monitoring lives" ping (healthchecks.io). It says scheduler, PHP and database
 * work, not that workers do; that is the standstill alert's job. A failure logs the exception
 * class only: its message carries the ping URL, which is a secret.
 */
class DeadMansSwitch
{
    public function ping(): void
    {
        $url = config('study.healthcheck_url');

        if (! $url) {
            return;
        }

        try {
            Http::timeout(5)->get($url);
        } catch (Throwable $e) {
            Log::warning('Study healthcheck ping failed: '.$e::class);
        }
    }
}
