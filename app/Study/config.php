<?php

// Study-only, removable: see app/Study/README.md. Merged as config('study').

// One job is one turn. The probe run measures P99 per pipeline; raise this if it exceeds 250 s.
$turnTimeout = 360;

return [
    // Off by default: a release can ship the folder and keep the page and the command away.
    'enabled' => (bool) env('STUDY_MODULE', false),

    // The project tag categories the planner sets on every study cell (names as in Settings → Tags).
    'tags' => [
        'focus' => 'focus profile',
        'duo' => 'duo profile',
        'scenario' => 'scenario',
        'wave' => 'wave',
        'study' => 'study',
        'block' => 'block',
        'focus_seat' => 'focus seat',
        // Set by GiveUp on a run given up for good; study:queue skips it.
        'superseded' => 'superseded',
    ],

    // The persona tag categories the planner sets on every study persona.
    'persona_tags' => [
        'study' => 'study',
        'first_name' => 'first name',
        'subgroup' => 'subgroup',
    ],

    // No finished turn for this long while runs are open: the page warns that the workers stand still.
    'stale_after_minutes' => 10,

    // Monitoring (study:watch, every minute). Reports are Discord only; alerts also go by mail.
    'watch' => [
        'repeat_alert_minutes' => (int) env('STUDY_ALERT_REPEAT_MINUTES', 180),
        // 0 = no report
        'report_every_hours' => (float) env('STUDY_REPORT_EVERY_HOURS', 6),
    ],

    'discord' => [
        'token' => env('DISCORD_BOT_TOKEN'),
        'channel_id' => env('DISCORD_CHANNEL_ID'),
        'application_id' => env('DISCORD_APPLICATION_ID'),
        'guild_id' => env('DISCORD_GUILD_ID'),
        // Set = the interactions endpoint (/status) exists.
        'public_key' => env('DISCORD_PUBLIC_KEY'),
    ],

    'alerts' => ['mail' => env('STUDY_ALERT_MAIL')],

    // Dead man's switch: pinged at the end of every successful study:watch.
    'healthcheck_url' => env('STUDY_HEALTHCHECK_URL'),

    'run' => [
        'turn_timeout' => $turnTimeout,
        // The per-run lock outlives the turn, so a killed worker's lock still expires before retry_after.
        'lock_seconds' => $turnTimeout + 60,
        // From the preregistration: a run with this many failed turns in a row is replaced.
        'max_failed_in_a_row' => 10,
        // Turns a dead worker or a timeout left running, closed by the next job: the turn itself kills the worker.
        'max_abandoned_in_a_row' => 3,
        // How long a job waits before it looks at the pause again.
        'pause_poll_seconds' => 60,
    ],

    'breaker' => [
        // Turns in a row without a word, over all runs: with six workers practically only an outage.
        'streak_limit' => 8,
        // signal => regex over job_logs.error. "HTTP 401" is ChildFailure::describe() (parallel tasks),
        // "status code 401" a RequestException from a direct call; a 402 arrives as the SDK's
        // InsufficientCreditsException, an OpenAI quota error in a body as insufficient_quota.
        'fatal_patterns' => [
            'auth' => '/HTTP (?:request returned status code )?(?:401|402|403)\b/',
            'quota' => '/insufficient_quota|InsufficientCreditsException/',
        ],
    ],

    // Shared by all workers and the web app on the one host; survives cache:clear.
    'pause_file' => storage_path('app/study/pause.json'),

    // The queue connection of RunStudyProject. retry_after stays above the per-run lock (and so
    // above the turn timeout), or a second worker takes over a live turn.
    'queue' => [
        'driver' => 'database',
        'connection' => env('DB_QUEUE_CONNECTION'),
        'table' => env('DB_QUEUE_TABLE', 'jobs'),
        'queue' => 'study',
        'retry_after' => $turnTimeout + 120,
        'after_commit' => false,
    ],
];
