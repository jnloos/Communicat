<?php

/*
|--------------------------------------------------------------------------
| Statistik-Seite: gespeicherte Vergleiche über viele Läufe
|--------------------------------------------------------------------------
*/

return [
    'heading' => 'Statistik',
    'subheading' => 'Vergleiche über viele Läufe. Eine Reihe ist ein Projekt-Filter plus ein Bezug auf Agenten.',
    'none_open' => 'Wähle links einen Vergleich oder lege einen neuen an.',

    'list' => [
        'heading' => 'Vergleiche',
        'create' => 'Neuer Vergleich',
        'empty' => 'Noch kein Vergleich.',
    ],

    'actions' => [
        'rename' => 'Umbenennen',
        'duplicate' => 'Duplizieren',
        'delete' => 'Löschen',
    ],

    'name' => [
        'create' => 'Neuer Vergleich',
        'rename' => 'Vergleich umbenennen',
        'field' => 'Name',
    ],

    'copy_name' => ':name (Kopie)',

    'confirm' => [
        'delete' => [
            'title' => 'Vergleich löschen?',
            'message' => 'Der Vergleich „:name“ und seine Reihen werden gelöscht. Projekte und Tags bleiben unberührt.',
        ],
        'remove_series' => [
            'title' => 'Reihe löschen?',
            'message' => 'Die Reihe „:label“ und ihre Filter werden gelöscht.',
        ],
    ],

    'dropped_tags' => '{1}Ein Tag-Wert dieses Vergleichs wurde inzwischen gelöscht und ist aus den Filtern herausgefallen.|[2,*]:count Tag-Werte dieses Vergleichs wurden inzwischen gelöscht und sind aus den Filtern herausgefallen.',

    'emptied_series' => '{1}Die Reihe :labels filtert nach einer Kategorie, deren gewählte Werte alle gelöscht wurden, und trifft deshalb keinen Lauf.|[2,*]Die Reihen :labels filtern nach einer Kategorie, deren gewählte Werte alle gelöscht wurden, und treffen deshalb keinen Lauf.',
    'quoted' => '„:label“',

    'autosave' => [
        'saved' => 'Automatisch gespeichert',
        'saving' => 'Speichert …',
    ],

    'series' => [
        'heading' => 'Reihen',
        'add' => 'Reihe hinzufügen',
        'remove' => 'Reihe entfernen',
        'default_label' => 'Reihe :n',
        'label' => 'Bezeichnung',
        'empty' => 'Noch keine Reihe.',
        'projects' => 'Projekte',
        'any' => 'beliebig',
        'pipeline' => 'Pipeline',
        'model' => 'Modell',
        'matches' => '{0}Kein Projekt passt.|{1}1 Projekt passt:|[2,*]:count Projekte passen:',
    ],

    'reference' => [
        'heading' => 'Bezug',
        'type' => 'Welche Agenten',
        'types' => [
            'persona_tags' => 'Experten mit Tags',
            'seat' => 'Ein Sitz',
            'expert_name' => 'Ein Experte nach Name',
            'top' => 'Der stärkste Agent des Laufs',
            'each' => 'Jeder Agent einzeln',
        ],
        'seat' => 'Sitz',
        'expert_name' => 'Name',
        'choose_expert' => 'Experte wählen …',
        'shared_hint' => 'Trifft der Bezug mehrere Agenten eines Laufs, zählt ihr gemeinsamer Anteil.',
        'run_level_hint' => 'Gini beschreibt den ganzen Lauf, der Bezug wird hier nicht verwendet.',
    ],

    'view' => [
        'heading' => 'Ansicht',
        'metric' => 'Kennzahl',
        'display' => 'Darstellung',
        'turn_limit' => 'Turns begrenzen auf',
        'all_turns' => 'alle',
        'mode' => 'Verlauf',
    ],

    'metrics' => [
        'turn_share' => 'Turn-Anteil',
        'word_share' => 'Wortanteil',
        'words_per_turn' => 'Wörter pro Turn',
        'turn_gini' => 'Gini (Turns)',
        'word_gini' => 'Gini (Wörter)',
    ],

    'displays' => [
        'table' => 'Tabelle',
        'bars' => 'Balken',
        'trajectory' => 'Verlauf',
    ],

    'modes' => [
        'cumulative' => 'Kumulativ',
        'rolling' => 'Gleitend',
    ],

    'line_with_seat' => ':label · Sitz :seat',

    'results' => [
        'empty' => 'Lege eine Reihe an, um Werte zu sehen.',
        'line' => 'Reihe',
        'n' => 'n',
        'mean' => 'Mittel',
        'min' => 'Min',
        'max' => 'Max',
        'per_project' => 'Werte je Projekt',
        'excluded' => '{1}„:label“: 1 Projekt fällt heraus (Bezug trifft niemanden oder kein gesprochener Turn).|[2,*]„:label“: :count Projekte fallen heraus (Bezug trifft niemanden oder kein gesprochener Turn).',
        'bar_text' => 'Mittel :mean · Spanne :min–:max · n = :n',
        'no_trajectory' => 'Den Verlauf gibt es nur für Turn- und Wortanteil.',
        'n_of' => ':label · n',
        'palette_repeats' => 'Mehr als 8 Linien: Farben wiederholen sich. Wähle einen engeren Bezug.',
        'too_short' => 'Für einen Verlauf braucht es mindestens zwei gesprochene Turns.',
    ],
];
