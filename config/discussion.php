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
