<?php

/*
|--------------------------------------------------------------------------
| Project chat: header, messages, composer, status indicator, welcome text
|--------------------------------------------------------------------------
*/

return [
    'header' => [
        'project_settings' => 'Project settings',
        'leave_project' => 'Leave project',
        'set_contributors' => 'Set Contributors',
    ],

    'confirm_leave' => [
        'title' => 'Leave project?',
        'action' => 'Leave project',
        'message' => 'You leave “:name” and no longer see it. The owner can invite you again.',
    ],

    'loading_older' => 'Loading older messages …',

    'message' => [
        'addressed' => 'To',
        'show_memory' => 'Show memory: :name',
        'open_thoughts' => 'Open thoughts',
    ],

    'controls' => [
        'run' => 'Run expert discussion',
        'pause' => 'Pause expert discussion',
        'start_label' => 'Discuss',
        'pause_label' => 'Pause',
        'sound_off' => 'Mute message sound',
        'sound_on' => 'Unmute message sound',
        'debug' => "This discussion's job report",
        'stats' => "This discussion's speaking shares",
        'aria_label' => 'Discussion controls',
        'busy_hint' => 'Another operation is in progress. Try again in a moment.',
        'waiting_hint' => 'Waiting for the current expert message…',
        'missing_topic' => 'This project has no description. The description is the topic of the discussion; add one in the project settings, then start again.',
    ],

    'indicator' => [
        'writing' => ':names is writing|:names are writing',
        'next_in' => 'Next contribution in :seconds s',
        'next_soon' => 'Next contribution soon',
    ],

    'welcome' => [
        'intro' => 'Discussion run on **:title**.',
        'purpose' => 'The selected experts discuss the topic in turns. Every turn is logged.',
        'start_hint' => 'Pick the experts, then start the run with **:button**.',
        'observer_hint' => 'You read along. Posting messages yourself is not possible.',
    ],
];
