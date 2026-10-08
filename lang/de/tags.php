<?php

/*
|--------------------------------------------------------------------------
| Globale Tags: Verwaltung, Formulare, Sidebar-Filter
|--------------------------------------------------------------------------
*/

return [
    'heading' => 'Tags',
    'subheading' => 'Kategorien mit festen Werten für Experten und Projekte. Die Modelle sehen sie nie.',
    'empty' => 'Noch keine Kategorie. Lege unten die erste an.',

    'scopes' => [
        'persona' => 'Experten',
        'project' => 'Projekte',
    ],

    'usage' => [
        'persona' => '{0}nicht verwendet|{1}1 Experte|[2,*]:count Experten',
        'project' => '{0}nicht verwendet|{1}1 Projekt|[2,*]:count Projekte',
    ],

    'form' => [
        'heading' => 'Tags',
        'help' => 'Nur für die Auswertung. Die Modelle sehen sie nie.',
    ],

    'fields' => [
        'category' => 'Kategorie',
        'value' => 'Wert',
        'unset' => 'Nicht gesetzt',
    ],

    'placeholders' => [
        'new_category' => 'Neue Kategorie',
        'new_value' => 'Neuer Wert',
    ],

    'actions' => [
        'add_category' => 'Kategorie anlegen',
        'add_value' => 'Wert hinzufügen',
        'add' => 'Hinzufügen',
        'rename' => 'Umbenennen',
        'delete' => 'Löschen',
        'drag' => 'Zum Sortieren ziehen',
        'category_menu' => 'Aktionen für die Kategorie',
        'value_menu' => 'Aktionen für den Wert',
    ],

    'confirm' => [
        'delete_category' => [
            'title' => 'Kategorie löschen?',
            'values' => '{0}Die Kategorie „:name“ hat keine Werte.|{1}Die Kategorie „:name“ und ihr einziger Wert werden gelöscht.|[2,*]Die Kategorie „:name“ und ihre :count Werte werden gelöscht.',
        ],
        'delete_value' => [
            'title' => 'Wert löschen?',
        ],
        'category_assignments' => [
            'persona' => '{0}Keinem Experten ist ein Wert davon zugeordnet.|{1}1 Experte verliert damit seine Zuordnung.|[2,*]:count Experten verlieren damit ihre Zuordnung.',
            'project' => '{0}Keinem Projekt ist ein Wert davon zugeordnet.|{1}1 Projekt verliert damit seine Zuordnung.|[2,*]:count Projekte verlieren damit ihre Zuordnung.',
        ],
        'value_assignments' => [
            'persona' => '{0}Der Wert „:name“ ist keinem Experten zugeordnet.|{1}Der Wert „:name“ ist 1 Experten zugeordnet; diese Zuordnung wird gelöscht.|[2,*]Der Wert „:name“ ist :count Experten zugeordnet; diese Zuordnungen werden gelöscht.',
            'project' => '{0}Der Wert „:name“ ist keinem Projekt zugeordnet.|{1}Der Wert „:name“ ist 1 Projekt zugeordnet; diese Zuordnung wird gelöscht.|[2,*]Der Wert „:name“ ist :count Projekten zugeordnet; diese Zuordnungen werden gelöscht.',
        ],
    ],

    'filter' => [
        'label' => 'Projekte',
        'button' => 'Projekte filtern',
        'button_active' => 'Projekte filtern (:count aktiv)',
        'title' => 'Projekte filtern',
        'clear' => 'Zurücksetzen',
        'clear_label' => 'Filter zurücksetzen',
        'no_matches' => 'Kein Projekt passt zum Filter.',
        'outside' => ':title – passt nicht zum aktiven Filter',
        'summary' => ':matches von :total · :values',
        'count' => '{1}:matches von :total Projekt|[0,*]:matches von :total Projekten',
        'modes' => [
            'merge' => [
                'label' => 'Merge',
                'description' => 'Projekte mit einem der gewählten Werte',
            ],
            'cut' => [
                'label' => 'Cut',
                'description' => 'Projekte, die jede gewählte Kategorie erfüllen',
            ],
        ],
    ],
];
