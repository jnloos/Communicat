<?php

/*
|--------------------------------------------------------------------------
| Project groups in the sidebar
|--------------------------------------------------------------------------
*/

return [
    'empty' => 'No projects',

    'menus' => [
        'project' => 'Project menu',
        'group' => 'Group menu',
        'file_in' => 'Group',
    ],

    'actions' => [
        'new_group' => 'New group …',
        'rename' => 'Rename',
        'delete_project' => 'Delete project',
        'delete_group' => 'Delete group',
    ],

    'fields' => [
        'name' => 'Group name',
    ],

    'create' => [
        'heading' => 'New group',
        'subheading' => 'The group is created and the project moved into it right away.',
        'submit' => 'Create',
    ],

    'rename_group' => [
        'heading' => 'Rename group',
    ],

    'rename_project' => [
        'heading' => 'Rename project',
    ],

    'confirm' => [
        'delete_project' => [
            'title' => 'Delete this project?',
            'message' => 'Every contribution of the discussion goes with it, as do this project\'s job and prompt logs. This cannot be undone.',
        ],
        'delete_group' => [
            'title' => 'Delete this group?',
            'message' => 'The projects stay and move back into the ungrouped list.',
        ],
    ],
];
