<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 60 min: an archive run takes minutes; a crashed run frees its lock before the next tick after one cycle.
Schedule::command('prompts:prune')->hourly()->withoutOverlapping(60);
