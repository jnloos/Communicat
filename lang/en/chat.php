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

    'message' => [
        'addressed' => 'Addressed:',
        'show_memory' => 'Show memory: :name',
        'open_thoughts' => 'Open thoughts',
    ],

    'composer' => [
        'placeholder' => 'Write a message…',
        'send' => 'Send your message',
        'run' => 'Run expert discussion',
        'pause' => 'Pause expert discussion',
        'start_label' => 'Start',
        'pause_label' => 'Pause',
        'sound_off' => 'Mute message sound',
        'sound_on' => 'Unmute message sound',
        'busy_hint' => 'Another operation is in progress. Try again in a moment.',
        'waiting_hint' => 'Waiting for the current expert message…',
    ],

    'indicator' => [
        'writing' => ':names is writing|:names are writing',
        'next_in' => 'Next contribution in :seconds s',
        'next_soon' => 'Next contribution soon',
    ],

    'welcome' => [
        'intro' => 'Welcome to Communicat. This is the discussion titled **:title**.',
        'purpose' => 'Use this chat to discuss the topic with your AI experts and other users.',
        'start_hint' => 'Add at least one expert and use **:button** in the composer to start the discussion.',
        'steer_hint' => 'You can also contribute your own messages to steer the conversation.',
    ],
];
