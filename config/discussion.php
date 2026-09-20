<?php

return [

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
    | Pipeline und Memory
    |--------------------------------------------------------------------------
    | default_pipeline — kurzer Klassenname aus app/Discussion/Pipelines.
    | history_keep     — n: so viele jüngste Nachrichten bleiben wörtlich (History).
    | summarize_batch  — b: Summarize läuft, sobald mehr als n + b Nachrichten
    |                    unzusammengefasst sind, und verdichtet alles bis auf n.
    |                    history_keep ist also kein hartes Fenster-Limit, sondern
    |                    der Zielwert, auf den Summarize herunterkomprimiert — das
    |                    tatsächliche Fenster pendelt zwischen history_keep und
    |                    history_keep + summarize_batch.
    */
    'default_pipeline' => env('DISCUSSION_DEFAULT_PIPELINE', 'RoundRobinPipeline'),
    'history_keep' => (int) env('DISCUSSION_HISTORY_KEEP', 20),
    'summarize_batch' => (int) env('DISCUSSION_SUMMARIZE_BATCH', 10),

];
