<?php

// Study-only, removable: see app/Study/README.md. Merged as config('study').
return [
    // Off by default: a release can ship the folder and keep the page and the command away.
    'enabled' => (bool) env('STUDY_MODULE', false),

    // The project tag categories the generator sets on every study cell (names as in Settings → Tags).
    'tags' => [
        'focus' => 'focus profile',
        'duo' => 'duo profile',
        'scenario' => 'scenario',
        'wave' => 'wave',
    ],

    // No finished turn for this long while runs are open: the page warns that the workers stand still.
    'stale_after_minutes' => 10,

    // The queue connection of RunStudyProject. One job drives a whole run, so retry_after stays
    // above the job's $timeout (3600 s), or a second worker takes over a live run.
    'queue' => [
        'driver' => 'database',
        'connection' => env('DB_QUEUE_CONNECTION'),
        'table' => env('DB_QUEUE_TABLE', 'jobs'),
        'queue' => 'study',
        'retry_after' => 3900,
        'after_commit' => false,
    ],
];
