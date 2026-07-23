<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Candidate selection strategy
    |--------------------------------------------------------------------------
    | Which CandidateStrategy the pipeline uses to build a turn's candidate pool.
    | 'funnel' (default) lets the moderator narrow to a subset; 'all' offers
    | every contributing expert. Exception: a user @-mention of a contributing
    | expert takes the deterministic mention shortcut below and bypasses the
    | strategy (and its route LLM call) entirely.
    */
    'candidate_strategy' => env('DISCUSSION_CANDIDATE_STRATEGY', 'funnel'),

    /*
    |--------------------------------------------------------------------------
    | @-mention shortcut
    |--------------------------------------------------------------------------
    | When the latest unanswered user message @-mentions contributing experts,
    | those experts become the candidate set directly: no route call, and with
    | a single mention no select call either — the mentioned expert answers,
    | even back-to-back.
    */
    'mention_shortcut' => (bool) env('DISCUSSION_MENTION_SHORTCUT', true),

    /*
    |--------------------------------------------------------------------------
    | Brevity signal
    |--------------------------------------------------------------------------
    | When the last brevity_streak expert turns were all at least
    | brevity_min_chars long, the next SPEAK prompt carries a hard brevity
    | instruction (1-2 short sentences) to break long-message monotony.
    */
    'brevity_streak' => (int) env('DISCUSSION_BREVITY_STREAK', 3),
    'brevity_min_chars' => (int) env('DISCUSSION_BREVITY_MIN_CHARS', 200),

    /*
    |--------------------------------------------------------------------------
    | Reading pause between auto-generated turns
    |--------------------------------------------------------------------------
    | After a persona message is shown, the next turn is queued with a delay
    | derived from the visible content length so users can read before the loop
    | continues. The message appears immediately; only the follow-up is delayed.
    */
    'reading_chars_per_second' => (int) env('DISCUSSION_READING_CHARS_PER_SECOND', 12),
    'reading_delay_min_seconds' => (int) env('DISCUSSION_READING_DELAY_MIN', 4),
    'reading_delay_max_seconds' => (int) env('DISCUSSION_READING_DELAY_MAX', 25),

    /*
    |--------------------------------------------------------------------------
    | User inclusion cadence
    |--------------------------------------------------------------------------
    | After this many consecutive expert messages since the last user message,
    | the moderator must hand back to the user (threshold = multiplier × N,
    | where N is the number of contributing experts).
    */
    'user_inclusion_multiplier' => (int) env('DISCUSSION_USER_INCLUSION_MULTIPLIER', 2),

    /*
    |--------------------------------------------------------------------------
    | Topic clarification (sparse project briefing)
    |--------------------------------------------------------------------------
    | When the project description is shorter than this many characters (after
    | trim) and no user message exists yet, the moderator must hand back to the
    | user with a concrete clarifying question instead of speculative debate.
    */
    'topic_clarification_min_description_length' => (int) env('DISCUSSION_TOPIC_CLARIFICATION_MIN_DESC', 40),

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
