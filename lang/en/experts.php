<?php

/*
|--------------------------------------------------------------------------
| Experts: list, filter, editor, memory flyout
|--------------------------------------------------------------------------
*/

return [
    'page_title' => 'Experts',

    'filter' => [
        'search_placeholder' => 'Search experts by name, role, or description...',
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
        'role' => 'Role',
        'description_help' => 'Shown in the UI and used as the persona core in the prompt.',
        'delete' => 'Delete Expert',
        'view' => 'Expert',
        'read_only' => 'Only administrators change experts.',
    ],

    'attributes' => [
        'single_line' => 'A value must be a single line.',
        'empty_hint' => 'Empty = not mentioned in any prompt.',
        'value_placeholder' => 'Value',
        'not_set' => 'not set',
        'persona_reads' => 'Persona reads:',
        'heading' => 'Attributes',
        'help' => 'Visible to the model, unlike tags. Whether and to whom it is shown is set per project.',
    ],

    'memory' => [
        'heading' => 'Memory of this discussion',
        'empty' => 'No memory yet. :name has not taken any notes so far.',
        'none_selected' => 'No expert selected.',
    ],
];
