<?php

/*
|--------------------------------------------------------------------------
| Project groups in the sidebar
|--------------------------------------------------------------------------
*/

return [
    'empty' => 'No projects',

    'ungrouped' => 'Unsorted chats',

    'menus' => [
        'project' => 'Project menu',
        'group' => 'Group menu',
        'file_in' => 'Group',
    ],

    'actions' => [
        'create_group' => 'Create group',
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
            'title' => 'Delete project?',
            'action' => 'Delete project',
            'message' => 'The project “:name” is deleted with every contribution and its job and prompt logs. This cannot be undone.',
        ],
        'delete_group' => [
            'title' => 'Delete this group?',
            'message' => 'The projects stay and move back into the ungrouped list.',
        ],
    ],
];
