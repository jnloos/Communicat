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

    'trajectory' => [
        'title' => 'Im Verlauf des Laufs',
        'cumulative' => 'Kumulativ',
        'rolling' => 'Gleitend',
        'cumulative_hint' => 'Anteil an allem bis zu diesem Turn Gesagten. Glättet sich schon rechnerisch: nach n Turns verschiebt ein weiterer ihn um höchstens etwa 1/n.',
        'rolling_hint' => '{1}Anteil nur am letzten Turn.|[2,*]Anteil an den letzten :count Turns. Erst wenn dieser stabil bleibt, verschiebt sich die Diskussion selbst nicht mehr.',
        'turn' => 'Turn :turn',
    ],

    'columns' => [
        'participant' => 'Teilnehmer',
        'turns' => 'Turns',
        'turn_share' => 'Anteil',
        'words' => 'Wörter',
        'word_share' => 'Anteil',
        'words_per_turn' => 'Ø Wörter',
    ],
];
