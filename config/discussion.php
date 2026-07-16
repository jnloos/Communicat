<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Candidate selection strategy
    |--------------------------------------------------------------------------
    | Which CandidateStrategy the pipeline uses to build a turn's candidate pool.
    | 'funnel' (default) lets the moderator narrow to a subset; 'all' offers
    | every contributing expert. Direct address (incl. @-mentions) is inferred
    | by the moderator from the transcript, not special-cased in code.
    */
    'candidate_strategy' => env('DISCUSSION_CANDIDATE_STRATEGY', 'funnel'),

    /*
    |--------------------------------------------------------------------------
    | Reading pause between auto-generated turns
    |--------------------------------------------------------------------------
    | After a persona message is shown, the next turn is queued with a delay
    | derived from the visible content length so users can read before the loop
    | continues. The message appears immediately; only the follow-up is delayed.
    */
    'reading_chars_per_second' => (int) env('DISCUSSION_READING_CHARS_PER_SECOND', 18),
    'reading_delay_min_seconds' => (int) env('DISCUSSION_READING_DELAY_MIN', 2),
    'reading_delay_max_seconds' => (int) env('DISCUSSION_READING_DELAY_MAX', 15),

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
    'summary_trigger_at' => 100,
    'summary_keep_recent' => 30,

];
