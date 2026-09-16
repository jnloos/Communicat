<?php

/*
|--------------------------------------------------------------------------
| Experts: list, filter, editor, memory flyout
|--------------------------------------------------------------------------
*/

return [
    'page_title' => 'Experts',

    'filter' => [
        'search_placeholder' => 'Search experts by name, job, or description...',
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
        'job' => 'Job',
        'description_help' => 'Shown in the UI and used as the persona core in the prompt.',
        'delete' => 'Delete Expert',
    ],

    'memory' => [
        'heading' => 'Memory of this discussion',
        'empty' => 'No memory yet. :name has not taken any notes so far.',
        'about_user' => 'About the user',
        'about' => 'About :name',
        'thoughts_of' => 'Thoughts of :name',
        'open_questions' => 'Open questions',
        'last_state' => 'Latest state of the conversation',
        'legacy_format' => 'The format will be updated with the next memory update.',
        'none_selected' => 'No expert selected.',
    ],
];
