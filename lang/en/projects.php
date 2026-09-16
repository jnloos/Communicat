<?php

/*
|--------------------------------------------------------------------------
| Projects: create, edit, import/export, contributors dialog
|--------------------------------------------------------------------------
*/

return [
    'page_title' => 'Projects',

    'fields' => [
        'title_help' => 'Enter a concise and recognizable project title.',
        'description_help' => 'Describe the project in a clear and concise way. This description will be used by AI systems, so make sure it\'s easy to understand and captures the core idea precisely.',
        'memory_reduction' => 'Memory Reduction',
        'memory_reduction_help' => 'This setting controls how many messages will be sent to the LLM. High reduction reduces token usage, but may also reduce the quality of the discussion.',
        'reduction' => [
            'high' => 'High',
            'standard' => 'Standard',
            'low' => 'Low',
        ],
    ],

    'create' => [
        'heading' => 'Create Project',
        'subheading' => 'Set the topic the experts will discuss.',
        'submit' => 'Start Discussion',
    ],

    'import' => [
        'heading' => 'Create from File',
        'description' => 'Upload a JSON export to create a full copy as a new project.',
        'dropzone_heading' => 'Drop a JSON export here or click to browse',
        'dropzone_text' => 'JSON, max. 20 MB',
        'remove_file' => 'Remove file',
        'submit' => 'Create from File',
        'creating' => 'Creating…',
        'invalid_file' => 'Invalid export file.',
        'missing_experts' => 'Some experts no longer exist and were skipped.',
        'untitled' => 'Untitled project',
        'copy_suffix' => '(copy)',
    ],

    'edit' => [
        'heading' => 'Edit Project',
        'subheading' => 'Changes apply to the next expert turn.',
        'submit' => 'Update Project',
        'export_json' => 'Export as JSON',
        'delete' => 'Delete Project',
    ],

    'contributors' => [
        'heading' => 'Choose Contributors',
        'subheading' => 'Pick up to one expert and invite other users.|Pick up to :count experts and invite other users.',
        'tab_experts' => 'Experts',
        'tab_users' => 'Users',
        'limit_reached' => 'Limit reached: at most one expert per project.|Limit reached: at most :count experts per project.',
        'limit_warning' => 'At most one expert per project.|At most :count experts per project.',
        'no_expert_matches' => 'No experts match the current filters.',
        'search_users' => 'Search users by name or email...',
        'no_user_matches' => 'No users match the current filters.',
    ],
];
