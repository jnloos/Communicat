<?php

/*
|--------------------------------------------------------------------------
| Statistics page: a question across many runs, answered as runs, shares or curves
|--------------------------------------------------------------------------
*/

return [
    'heading' => 'Statistics',
    'subheading' => 'Ask a question across many runs. Every dot is one run.',
    'none_open' => 'Pick a template or one of your questions on the left, or ask a new one.',

    'list' => [
        'questions' => 'Questions',
        'templates' => 'Templates',
        'create' => 'New question',
        'empty' => 'No question yet.',
        'no_templates' => 'No template yet.',
        'no_templates_admin' => 'Save a question as a template to share it.',
    ],

    'actions' => [
        'move_up' => 'Move up',
        'move_down' => 'Move down',
        'rename' => 'Rename',
        'duplicate' => 'Duplicate',
        'save_as_template' => 'Save as template',
        'export_raw' => 'Export raw data as CSV',
        'copy_to_adjust' => 'Copy to adjust',
        'delete' => 'Delete',
    ],

    'name' => [
        'rename' => 'Rename',
        'field' => 'Title',
        'hint' => 'Leave empty for no title.',
    ],

    'notes' => [
        'label' => 'Question and notes',
        'placeholder' => 'What do you want to find out, and what did you conclude?',
    ],

    'untitled' => 'Untitled question',

    'copy_name' => 'Copy of :name',

    'confirm' => [
        'delete' => [
            'title' => 'Delete question?',
            'message' => '“:name” will be deleted. Projects and tags stay untouched.',
        ],
        'delete_template' => [
            'title' => 'Delete template?',
            'message' => 'The template “:name” will be deleted for everyone. Questions made from it stay.',
        ],
    ],

    'template' => [
        'without_groups' => 'Groups are personal, so the template does not use them.',
        'view_only_notice' => 'This template is view only. Copy it to adjust.',
    ],

    'autosave' => [
        'saved' => 'Saved automatically',
        'saving' => 'Saving …',
    ],

    'sentence' => [
        'of' => 'of',
        'in' => 'in',
        'compared_by' => 'compared by',
        'split_by' => 'split by',
        'everyone' => 'everyone',
        'all_runs' => 'all runs',
        'nothing' => 'nothing',
        'all_turns' => 'over all turns',
        'turns' => 'over turns :from to :to',
        'from_turn' => 'from turn :from onwards',
        'beyond_shortest_run' => 'from turn :from, beyond the shortest run of :count turns',
        'of_people' => 'of :people',
        'in_projects' => 'in :projects',
        'compared_by_dimension' => 'compared by :dimension',
        'split_by_dimension' => 'split by :dimension',
        'deleted' => 'deleted category',
        'deleted_value' => 'deleted value',
        'deleted_group' => 'deleted group',
    ],

    'controls' => [
        'metric' => 'Measure',
        'expert_names' => 'Experts by name',
        'any_expert' => 'Any expert',
        'aggregate' => 'Count the group',
        'pipelines' => 'Pipelines',
        'models' => 'Models',
        'groups' => 'Groups',
        'turn_from' => 'From turn',
        'turn_to' => 'To turn',
        'compare_by' => 'Compare by',
        'baseline' => 'Baseline',
        'split_by' => 'Split by',
        'none' => 'Nothing',
        'gini_hint' => 'Gini describes the whole run, so it counts everyone.',
    ],

    'aggregates' => [
        'together' => 'together',
        'per_head' => 'per head',
    ],

    'dimensions' => [
        'pipeline' => 'Pipeline',
        'model' => 'Model',
        'group' => 'Group',
    ],

    'sources' => [
        'project_tag' => 'Project',
        'persona_tag' => 'Expert',
        'run' => 'Run',
        'group' => 'Group',
    ],

    /* A listed item names where it comes from in square brackets: "Pipeline [Run]". */
    'source_label' => ':name [:source]',

    'metrics' => [
        'turn_share' => 'Turn share',
        'word_share' => 'Word share',
        'words_per_turn' => 'Words per turn',
        'turn_gini' => 'Gini of turns',
        'word_gini' => 'Gini of words',
    ],

    'forms' => [
        'points' => 'Runs',
        'composition' => 'Shares',
        'trajectory' => 'Over the run',
    ],

    'form_hints' => [
        'points' => 'Each run as a dot',
        'composition' => 'Groups as parts of 100 %',
        'trajectory' => 'Mean share per turn',
    ],

    'curves' => [
        'cumulative' => 'Cumulative',
        'rolling' => 'Rolling',
    ],

    'result' => [
        'dropped_tags' => '{1}1 tag value this question used no longer exists and was removed. A category left with no value still filters and matches no run: open its chip and choose other values.|[2,*]:count tag values this question used no longer exist and were removed. A category left with no value still filters and matches no run: open its chip and choose other values.',
        'n_of' => ':label, n',
        'fair_share' => 'Fair share',
        'fair_share_of' => ':label, fair share',
        'empty' => 'No run answers this question. Widen the runs or the people.',
        'all' => 'All',
        'no_value' => 'no value',
        'mean' => 'Mean',
        'delta' => 'Effect size: :delta',
        'too_short' => 'A curve needs at least two spoken turns.',
        'run_table' => '{1}The run behind these numbers|[2,*]The :count runs behind these numbers',
        'columns' => [
            'run' => 'Run',
            'panel' => 'Panel',
            'group' => 'Group',
            'value' => 'Value',
        ],
    ],
];
