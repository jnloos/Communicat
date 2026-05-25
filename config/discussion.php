<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Candidate selection strategy
    |--------------------------------------------------------------------------
    | Which CandidateStrategy the pipeline uses to build a turn's candidate pool.
    | 'funnel' (default) lets the moderator narrow to a subset; 'all' offers
    | every contributing expert. An @-mention always overrides the strategy.
    */
    'candidate_strategy' => env('DISCUSSION_CANDIDATE_STRATEGY', 'funnel'),

    /*
    |--------------------------------------------------------------------------
    | Adjacency-pair generation
    |--------------------------------------------------------------------------
    | When true, the moderator may instruct the next speaker (via the Directive)
    | to OPEN an adjacency pair (pose a direct question/request to a named peer)
    | or CLOSE one (answer/react to whoever addressed them). Turning it off
    | reverts to the prior behaviour (no explicit pair steering).
    */
    'generate_pairs' => env('DISCUSSION_GENERATE_PAIRS', true),

    /*
    |--------------------------------------------------------------------------
    | Summarizer thresholds
    |--------------------------------------------------------------------------
    | summary_trigger_at  — compression runs only when the number of unsummarized
    |                       messages EXCEEDS this value (replaces buffer_threshold).
    | summary_keep_recent — how many newest messages stay verbatim; everything
    |                       older is compressed (replaces buffer_keep).
    | Per-project overrides live in project.settings under the same keys; the
    | legacy buffer_threshold / buffer_keep keys are still accepted as fallback.
    */
    'summary_trigger_at'  => 100,
    'summary_keep_recent' => 30,

];
