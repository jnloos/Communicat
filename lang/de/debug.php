<?php

/*
|--------------------------------------------------------------------------
| Job-Debug-Seite
|--------------------------------------------------------------------------
*/

return [
    'title' => 'Job-Debug',
    'subheading' => 'Bericht über die letzten 50 Jobs.',
    'select_project' => 'Projekt wählen',

    'live' => [
        'on' => 'LIVE',
        'paused' => 'PAUSIERT',
        'pause_hint' => 'Live-Updates pausieren, um in Ruhe zu lesen',
        'resume_hint' => 'Live-Updates fortsetzen',
    ],

    'columns' => [
        'job' => 'Job',
        'status' => 'Status',
        'started' => 'Start',
        'duration' => 'Dauer',
    ],

    'status' => [
        'success' => 'erfolgreich',
        'failed' => 'fehlgeschlagen',
        'running' => 'läuft…',
    ],

    'empty' => 'Noch keine Jobs gelaufen.',

    'detail' => [
        'heading' => 'Job #:id · :class',
        'close' => '✕ schließen',
        'tab_prompts' => 'Prompts (:count)',
        'tab_messages' => 'Nachrichten (:count)',
        'prompt' => 'Prompt',
        'response' => 'Antwort',
        'error' => 'Fehler',
        'no_prompts' => 'Keine Prompts für diesen Job aufgezeichnet.',
        'no_messages' => 'Keine Nachrichten von diesem Job erzeugt.',
        'system_sender' => 'System',
    ],
];
