<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 60 min: an archive run takes minutes; a crashed run frees its lock before the next tick after one cycle.
Schedule::command('prompts:prune')->hourly()->withoutOverlapping(60);

// Study monitoring (docs/study.md): only while STUDY_MODULE is on.
Schedule::command('study:watch')->everyMinute()->withoutOverlapping(5)->when(fn () => config('study.enabled'));
