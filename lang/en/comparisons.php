<?php

/*
|--------------------------------------------------------------------------
| Statistics page: saved comparisons across many runs
|--------------------------------------------------------------------------
*/

return [
    'heading' => 'Statistics',
    'subheading' => 'Comparisons across many runs. A series is a project filter plus a reference to agents.',
    'none_open' => 'Pick a comparison on the left or create a new one.',

    'list' => [
        'heading' => 'Comparisons',
        'create' => 'New comparison',
        'empty' => 'No comparison yet.',
    ],

    'actions' => [
        'rename' => 'Rename',
        'duplicate' => 'Duplicate',
        'delete' => 'Delete',
    ],

    'name' => [
        'create' => 'New comparison',
        'rename' => 'Rename comparison',
        'field' => 'Name',
    ],

    'copy_name' => ':name (copy)',

    'confirm' => [
        'delete' => [
            'title' => 'Delete comparison?',
            'message' => 'The comparison “:name” and its series will be deleted. Projects and tags stay untouched.',
        ],
        'remove_series' => [
            'title' => 'Delete series?',
            'message' => 'The series “:label” and its filters will be deleted.',
        ],
    ],

    'dropped_tags' => '{1}A tag value of this comparison has since been deleted and dropped out of its filters.|[2,*]:count tag values of this comparison have since been deleted and dropped out of its filters.',

    'emptied_series' => '{1}The series :labels filters by a category whose chosen values have all been deleted, so it matches no run.|[2,*]The series :labels filter by a category whose chosen values have all been deleted, so they match no run.',
    'quoted' => '“:label”',

    'autosave' => [
        'saved' => 'Saved automatically',
        'saving' => 'Saving …',
    ],

    'series' => [
        'heading' => 'Series',
        'add' => 'Add series',
        'remove' => 'Remove series',
        'default_label' => 'Series :n',
        'label' => 'Label',
        'empty' => 'No series yet.',
        'projects' => 'Projects',
        'any' => 'any',
        'pipeline' => 'Pipeline',
        'model' => 'Model',
        'matches' => '{0}No project matches.|{1}1 project matches:|[2,*]:count projects match:',
    ],

    'reference' => [
        'heading' => 'Reference',
        'type' => 'Which agents',
        'types' => [
            'persona_tags' => 'Personas with tags',
            'seat' => 'One seat',
            'expert_name' => 'One expert by name',
            'top' => 'The run’s strongest agent',
            'each' => 'Every agent on its own',
        ],
        'seat' => 'Seat',
        'expert_name' => 'Name',
        'choose_expert' => 'Choose an expert …',
        'shared_hint' => 'If the reference hits several agents of a run, their combined share counts.',
        'run_level_hint' => 'Gini describes the whole run, the reference is not used here.',
    ],

    'view' => [
        'heading' => 'View',
        'metric' => 'Measure',
        'display' => 'Display',
        'turn_limit' => 'Limit turns to',
        'all_turns' => 'all',
        'mode' => 'Curve',
    ],

    'metrics' => [
        'turn_share' => 'Turn share',
        'word_share' => 'Word share',
        'words_per_turn' => 'Words per turn',
        'turn_gini' => 'Gini (turns)',
        'word_gini' => 'Gini (words)',
    ],

    'displays' => [
        'table' => 'Table',
        'bars' => 'Bars',
        'trajectory' => 'Over the run',
    ],

    'modes' => [
        'cumulative' => 'Cumulative',
        'rolling' => 'Rolling',
    ],

    'line_with_seat' => ':label · seat :seat',

    'results' => [
        'empty' => 'Add a series to see values.',
        'line' => 'Series',
        'n' => 'n',
        'mean' => 'Mean',
        'min' => 'Min',
        'max' => 'Max',
        'per_project' => 'Values per project',
        'excluded' => '{1}“:label”: 1 project drops out (the reference hits nobody, or no turn was spoken).|[2,*]“:label”: :count projects drop out (the reference hits nobody, or no turn was spoken).',
        'bar_text' => 'Ø :mean · :min–:max · n = :n',
        'no_trajectory' => 'The curve exists for turn share and word share only.',
        'n_of' => ':label · n',
        'palette_repeats' => 'More than 8 lines: colours repeat. Choose a narrower reference.',
        'too_short' => 'A curve needs at least two spoken turns.',
    ],
];
