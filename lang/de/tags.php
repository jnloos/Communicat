<?php

/*
|--------------------------------------------------------------------------
| Globale Tags: Verwaltung, Formulare, Sidebar-Filter
|--------------------------------------------------------------------------
*/

return [
    'heading' => 'Tags',
    'subheading' => 'Kategorien mit festen Werten für Personas und Projekte. Die Modelle sehen sie nie.',
    'empty' => 'Noch keine Kategorie. Lege unten die erste an.',

    'scopes' => [
        'persona' => 'Personas',
        'project' => 'Projekte',
    ],

    'assignments' => '{0}nicht zugeordnet|{1}1 Zuordnung|[2,*]:count Zuordnungen',

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
        'add_value' => 'Hinzufügen',
        'rename' => 'Zum Umbenennen klicken',
        'drag' => 'Zum Sortieren ziehen',
        'delete_category' => 'Kategorie löschen',
        'delete_value' => 'Wert löschen',
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
            'persona' => '{0}Keiner Persona ist ein Wert davon zugeordnet.|{1}1 Persona verliert damit ihre Zuordnung.|[2,*]:count Personas verlieren damit ihre Zuordnung.',
            'project' => '{0}Keinem Projekt ist ein Wert davon zugeordnet.|{1}1 Projekt verliert damit seine Zuordnung.|[2,*]:count Projekte verlieren damit ihre Zuordnung.',
        ],
        'value_assignments' => [
            'persona' => '{0}Der Wert „:name“ ist keiner Persona zugeordnet.|{1}Der Wert „:name“ ist 1 Persona zugeordnet; diese Zuordnung wird gelöscht.|[2,*]Der Wert „:name“ ist :count Personas zugeordnet; diese Zuordnungen werden gelöscht.',
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
        'remove' => ':value entfernen',
        'no_matches' => 'Kein Projekt passt zum Filter.',
        'no_matches_cut' => 'Kein Projekt hat alle gewählten Werte. Probier Merge.',
        'outside' => 'Passt nicht zum aktiven Filter',
        'count' => '{1}:matches von :total Projekt|[0,*]:matches von :total Projekten',
        'modes' => [
            'merge' => [
                'label' => 'Merge',
                'description' => 'Projekte mit einem der gewählten Werte',
            ],
            'cut' => [
                'label' => 'Cut',
                'description' => 'Projekte mit allen gewählten Werten',
            ],
        ],
    ],
];
