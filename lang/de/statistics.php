<?php

/*
|--------------------------------------------------------------------------
| Statistik-Panel: die zwei Messgrößen eines Laufs
|--------------------------------------------------------------------------
*/

return [
    'title' => 'Redeanteile',
    'subheading' => 'Wer wie oft das Wort hatte und wie viel Text dabei entstand.',
    'empty' => 'Noch kein Beitrag gesprochen. Starten Sie den Lauf, dann füllen sich die Anteile.',

    'turn_share' => 'Turn-Anteil',
    'word_share' => 'Gesprächsanteil',
    'turns_total' => '{0}keine Turns|{1}1 Turn insgesamt|[2,*]:count Turns insgesamt',
    'words_total' => '{0}keine Wörter|{1}1 Wort insgesamt|[2,*]:count Wörter insgesamt',

    'gini' => 'Gini',

    'columns' => [
        'participant' => 'Teilnehmer',
        'turns' => 'Turns',
        'turn_share' => 'Anteil',
        'words' => 'Wörter',
        'word_share' => 'Anteil',
        'words_per_turn' => 'Ø Wörter',
    ],
];
