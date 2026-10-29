<?php

/*
|--------------------------------------------------------------------------
| Experts: list, filter, editor, memory flyout
|--------------------------------------------------------------------------
*/

return [
    'page_title' => 'Experts',

    'filter' => [
        'search_placeholder' => 'Search experts by name, description, or attribute...',
        'tags' => 'Tags',
        'tags_active' => 'Tags (:count)',
        'button' => 'Filter experts by tag',
        'button_active' => 'Filter experts by tag (:count active)',
        'modes' => [
            'merge' => 'Experts that match any picked value',
            'cut' => 'Experts that match every picked category',
        ],
    ],

    'list' => [
        'heading' => 'Edit Experts',
        'count' => ':count expert|:count experts',
        'create' => 'Create Expert',
        'no_matches' => 'No experts match the current filters.',
        'empty' => 'No experts yet.',
    ],

    'editor' => [
        'create' => 'Create Expert',
        'update' => 'Update Expert',
        'subheading' => 'Define this expert\'s identity.',
        'change_avatar' => 'Click to change avatar',
        'uploading' => 'Uploading…',
        'description_help' => 'Shown in the UI and used as the persona core in the prompt.',
        'delete' => 'Delete Expert',
        'view' => 'Expert',
        'read_only' => 'Only administrators change experts.',
    ],

    'confirm' => [
        'title' => 'Delete expert?',
        'action' => 'Delete expert',
        'message' => '{0}“:name” sits in no project and is deleted. This cannot be undone.|{1}“:name” sits in 1 project and is deleted. Their turns there leave the turn budget and the metrics. This cannot be undone.|[2,*]“:name” sits in :count projects and is deleted. Their turns there leave the turn budget and the metrics. This cannot be undone.',
    ],

    'attributes' => [
        'single_line' => 'A value must be a single line.',
        'value_placeholder' => 'Value',
        'summary_none' => 'Not set',
        'not_set' => 'not set',
        'persona_reads' => 'Persona reads:',
        'heading' => 'Attributes',
        'help' => 'The models read these; each project sets who sees them. Blank fields are left out of the prompts.',
    ],

    'memory' => [
        'heading' => 'Memory of this discussion',
        'empty' => 'No memory yet. :name has not taken any notes so far.',
        'none_selected' => 'No expert selected.',
    ],
];
