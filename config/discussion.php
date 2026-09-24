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
    | default_pipeline    — kurzer Klassenname aus app/Discussion/Pipelines.
    | summarize_threshold — x: Summarize läuft, sobald mindestens x unzusammen-
    |                       gefasste Nachrichten vorliegen.
    | summarize_oldest    — y: dann werden genau die y ältesten davon in das
    |                       Long-Term-Memory verdichtet; die restlichen x - y
    |                       bleiben wörtlich in der History.
    |
    | Beide sind nur Voreinstellungen: ein Projekt kann sie in den Spalten
    | projects.summarize_threshold / projects.summarize_oldest überschreiben
    | (siehe Project::summarizeThreshold() / summarizeOldest()). y muss kleiner
    | als x sein, sonst bliebe nach dem Verdichten keine History übrig.
    */
    'default_pipeline' => env('DISCUSSION_DEFAULT_PIPELINE', 'RoundRobinPipeline'),
    'summarize_threshold' => (int) env('DISCUSSION_SUMMARIZE_THRESHOLD', 40),
    'summarize_oldest' => (int) env('DISCUSSION_SUMMARIZE_OLDEST', 20),

];
