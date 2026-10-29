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
    'gini_title' => '{1}Gini :gini (0 = equal, max :max with 1 person)|[0,*]Gini :gini (0 = equal, max :max with :count people)',

    'trajectory' => [
        'title' => 'Over the course of the run',
        'cumulative' => 'Cumulative',
        'rolling' => 'Rolling',
        'cumulative_hint' => 'Share of all turns so far.',
        'rolling_hint' => '{1}Share of the last turn.|[2,*]Share of the last :count turns.',
        'cumulative_tooltip' => 'Share of all turns so far',
        'rolling_tooltip' => '{1}Share of the last turn|[2,*]Share of the last :count turns',
        'turns_tooltip' => 'Share of turns',
        'words_tooltip' => 'Share of words',
        'turn' => 'Turn :turn',
    ],

    'columns' => [
        'participant' => 'Participant',
        'turns' => 'Turns',
        'turn_share' => 'Turn share',
        'words' => 'Words',
        'word_share' => 'Word share',
        'words_per_turn' => 'Avg words',
    ],
];
