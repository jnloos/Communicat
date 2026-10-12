<?php

/*
|--------------------------------------------------------------------------
| Statistics page: a question across many runs, answered in one fixed form
|--------------------------------------------------------------------------
*/

return [
    'heading' => 'Statistics',
    'subheading' => 'Ask a question across many runs. Every dot is one run.',
    'none_open' => 'Pick a question on the left, start from a template, or ask a new one.',

    'list' => [
        'questions' => 'My questions',
        'templates' => 'Templates',
        'create' => 'New question',
        'empty' => 'No question yet.',
        'no_templates' => 'No template yet. Save a question as a template to reuse it.',
    ],

    'actions' => [
        'rename' => 'Rename',
        'duplicate' => 'Duplicate',
        'save_as_template' => 'Save as template',
        'delete' => 'Delete',
    ],

    'name' => [
        'rename' => 'Rename',
        'field' => 'Name',
        'hint' => 'Leave empty to name it after its sentence.',
    ],

    'copy_name' => ':name (copy)',

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
        'turn_budget' => '{1}runs of 1 turn|[2,*]runs of :count turns',
        'name_compared_by' => 'by :dimension',
        'name_split_by' => 'per :dimension',
        'deleted' => 'deleted category',
        'deleted_value' => 'deleted value',
    ],

    'controls' => [
        'metric' => 'Measure',
        'expert_name' => 'Expert by name',
        'any_expert' => 'Any expert',
        'aggregate' => 'Count the group',
        'pipelines' => 'Pipelines',
        'models' => 'Models',
        'turn_budget' => 'Turn budget',
        'any_budget' => 'Any budget',
        'compare_by' => 'Compare by',
        'split_by' => 'Split by',
        'none' => 'Nothing',
        'people_hint' => 'Several tags: every category must match, any value within one.',
        'gini_hint' => 'Gini describes the whole run, so it counts everyone.',
        'curve' => 'Curve',
    ],

    'aggregates' => [
        'together' => 'together',
        'per_head' => 'per head',
    ],

    'aggregate_hints' => [
        'together' => 'The group’s shares added up. All groups add up to 100 %.',
        'per_head' => 'The share of an average member, so groups of different size compare fairly.',
    ],

    'dimensions' => [
        'pipeline' => 'Pipeline',
        'model' => 'Model',
        'persona' => ':category (persona)',
    ],

    'metrics' => [
        'turn_share' => 'Turn share',
        'word_share' => 'Word share',
        'words_per_turn' => 'Words per turn',
        'turn_gini' => 'Gini (turns)',
        'word_gini' => 'Gini (words)',
    ],

    'forms' => [
        'points' => 'Runs',
        'composition' => 'Shares',
        'trajectory' => 'Over the run',
        'unsupported' => [
            'composition' => 'Only when the groups add up to everyone: a share, compared by a persona tag, everyone, counted together.',
            'trajectory' => 'Only for turn share and word share.',
        ],
    ],

    'curves' => [
        'cumulative' => 'Cumulative',
        'rolling' => 'Rolling',
    ],

    'result' => [
        'runs' => '{0}No run matches.|{1}1 run|[2,*]:count runs',
        'excluded' => '{1}1 run has no value here (no spoken turn, nobody matches, or no value in the compared or split category).|[2,*]:count runs have no value here (no spoken turn, nobody matches, or no value in the compared or split category).',
        'dropped_tags' => '{1}1 tag value this question used no longer exists and was removed. A category left with no value still filters and matches no run: open its chip and choose other values.|[2,*]:count tag values this question used no longer exist and were removed. A category left with no value still filters and matches no run: open its chip and choose other values.',
        'n_of' => ':label · n',
        'empty' => 'No run answers this question. Widen the runs or the people.',
        'all' => 'All',
        'no_value' => 'no value',
        'fair_share' => 'Dashed line: fair share, what the group would get if everyone spoke equally.',
        'mean' => 'Mean',
        'delta' => ':to minus :from: :delta',
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
