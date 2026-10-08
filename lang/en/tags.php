<?php

/*
|--------------------------------------------------------------------------
| Global tags: management, forms, sidebar filter
|--------------------------------------------------------------------------
*/

return [
    'heading' => 'Tags',
    'subheading' => 'Categories with fixed values for personas and projects. The models never see them.',
    'empty' => 'There is no category in this area yet.',

    'scopes' => [
        'persona' => 'Personas',
        'project' => 'Projects',
    ],

    'assignments' => '{0}unassigned|{1}1 assignment|[2,*]:count assignments',

    'form' => [
        'heading' => 'Tags',
        'help' => 'For the evaluation only. The models never see them.',
    ],

    'fields' => [
        'category' => 'Category',
        'value' => 'Value',
        'unset' => '—',
    ],

    'placeholders' => [
        'new_category' => 'New category',
        'new_value' => 'New value',
    ],

    'actions' => [
        'add_category' => 'Add category',
        'add_value' => 'Add',
        'rename' => 'Click to rename',
        'drag' => 'Drag to reorder',
        'delete_category' => 'Delete category',
        'delete_value' => 'Delete value',
    ],

    'confirm' => [
        'delete_category' => [
            'title' => 'Delete category?',
            'values' => '{0}The category “:name” has no values.|{1}The category “:name” and its only value will be deleted.|[2,*]The category “:name” and its :count values will be deleted.',
        ],
        'delete_value' => [
            'title' => 'Delete value?',
        ],
        'category_assignments' => [
            'persona' => '{0}No persona carries any of its values.|{1}1 persona loses its assignment.|[2,*]:count personas lose their assignment.',
            'project' => '{0}No project carries any of its values.|{1}1 project loses its assignment.|[2,*]:count projects lose their assignment.',
        ],
        'value_assignments' => [
            'persona' => '{0}The value “:name” is not assigned to any persona.|{1}The value “:name” is assigned to 1 persona; that assignment will be deleted.|[2,*]The value “:name” is assigned to :count personas; these assignments will be deleted.',
            'project' => '{0}The value “:name” is not assigned to any project.|{1}The value “:name” is assigned to 1 project; that assignment will be deleted.|[2,*]The value “:name” is assigned to :count projects; these assignments will be deleted.',
        ],
    ],

    'filter' => [
        'button' => 'Filter',
        'clear' => 'Clear filter',
        'no_matches' => 'No project matches the filter.',
    ],
];
