<?php

/*
|--------------------------------------------------------------------------
| Statistics panel: the two measures of one run
|--------------------------------------------------------------------------
*/

return [
    'title' => 'Speaking shares',
    'subheading' => 'Who held the floor how often, and how much text that produced.',
    'empty' => 'No contribution spoken yet. Start the run and the shares will fill in.',

    'turn_share' => 'Turn share',
    'word_share' => 'Conversation share',
    'turns_total' => '{0}no turns|{1}1 turn in total|[2,*]:count turns in total',
    'words_total' => '{0}no words|{1}1 word in total|[2,*]:count words in total',

    'gini' => 'Gini',

    'trajectory' => [
        'title' => 'Over the course of the run',
        'cumulative' => 'Cumulative',
        'rolling' => 'Rolling',
        'cumulative_hint' => 'Share of everything said up to each turn. Settles by arithmetic alone: after n turns one more moves it by at most about 1/n.',
        'rolling_hint' => '{1}Share of the last turn only.|[2,*]Share of the last :count turns. Only when this holds steady has the discussion itself stopped shifting.',
        'turn' => 'Turn :turn',
    ],

    'columns' => [
        'participant' => 'Participant',
        'turns' => 'Turns',
        'turn_share' => 'Share',
        'words' => 'Words',
        'word_share' => 'Share',
        'words_per_turn' => 'Avg words',
    ],
];
