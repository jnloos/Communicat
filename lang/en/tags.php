<?php

/*
|--------------------------------------------------------------------------
| Global tags: management, forms, sidebar filter
|--------------------------------------------------------------------------
*/

return [
    'heading' => 'Tags',
    'subheading' => 'Categories with fixed values for experts and projects. The models never see them.',
    'empty' => 'No category yet. Add the first one below.',

    'scopes' => [
        'persona' => 'Experts',
        'project' => 'Projects',
    ],

    'usage' => [
        'persona' => '{0}not used|{1}1 expert|[2,*]:count experts',
        'project' => '{0}not used|{1}1 project|[2,*]:count projects',
    ],

    'form' => [
        'heading' => 'Tags',
        'help' => 'For the evaluation only. The models never see them.',
        'none_set' => 'None set',
    ],

    'fields' => [
        'category' => 'Category',
        'value' => 'Value',
        'unset' => 'Not set',
    ],

    'placeholders' => [
        'new_category' => 'New category',
        'new_value' => 'New value',
    ],

    'actions' => [
        'add_category' => 'Add category',
        'add_value' => 'Add value',
        'add' => 'Add',
        'rename' => 'Rename',
        'delete' => 'Delete',
        'drag' => 'Drag to reorder',
        'category_menu' => 'Category actions',
        'value_menu' => 'Value actions',
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
            'persona' => '{0}No expert carries any of its values.|{1}1 expert loses its assignment.|[2,*]:count experts lose their assignment.',
            'project' => '{0}No project carries any of its values.|{1}1 project loses its assignment.|[2,*]:count projects lose their assignment.',
        ],
        'value_assignments' => [
            'persona' => '{0}The value “:name” is not assigned to any expert.|{1}The value “:name” is assigned to 1 expert; that assignment will be deleted.|[2,*]The value “:name” is assigned to :count experts; these assignments will be deleted.',
            'project' => '{0}The value “:name” is not assigned to any project.|{1}The value “:name” is assigned to 1 project; that assignment will be deleted.|[2,*]The value “:name” is assigned to :count projects; these assignments will be deleted.',
        ],
    ],

    'filter' => [
        'label' => 'Projects',
        'button' => 'Filter projects',
        'button_active' => 'Filter projects (:count active)',
        'title' => 'Filter projects',
        'clear' => 'Clear',
        'clear_label' => 'Clear filter',
        'no_matches' => 'No project matches the filter.',
        'outside' => ':title – does not match the active filter',
        'summary' => ':matches of :total · :values',
        'count' => '{1}:matches of :total project|[0,*]:matches of :total projects',
        'modes' => [
            'merge' => [
                'label' => 'Merge',
                'description' => 'Projects that match any picked value',
            ],
            'cut' => [
                'label' => 'Cut',
                'description' => 'Projects that match every picked category',
            ],
        ],
    ],
];
