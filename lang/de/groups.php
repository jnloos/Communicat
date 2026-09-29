<?php

/*
|--------------------------------------------------------------------------
| Projektgruppen in der Seitenleiste
|--------------------------------------------------------------------------
*/

return [
    'empty' => 'Keine Projekte',

    'menus' => [
        'project' => 'Projektmenü',
        'group' => 'Gruppenmenü',
        'file_in' => 'Gruppe',
    ],

    'actions' => [
        'new_group' => 'Neue Gruppe …',
        'rename' => 'Umbenennen',
        'delete_project' => 'Projekt löschen',
        'delete_group' => 'Gruppe löschen',
    ],

    'fields' => [
        'name' => 'Name der Gruppe',
    ],

    'create' => [
        'heading' => 'Neue Gruppe',
        'subheading' => 'Die Gruppe wird angelegt und das Projekt sofort hineingelegt.',
        'submit' => 'Anlegen',
    ],

    'rename_group' => [
        'heading' => 'Gruppe umbenennen',
    ],

    'rename_project' => [
        'heading' => 'Projekt umbenennen',
    ],

    'confirm' => [
        'delete_project' => [
            'title' => 'Projekt löschen?',
            'message' => 'Damit verschwinden auch alle Beiträge der Diskussion sowie die Job- und Prompt-Logs dieses Projekts. Das lässt sich nicht rückgängig machen.',
        ],
        'delete_group' => [
            'title' => 'Gruppe löschen?',
            'message' => 'Die Projekte bleiben erhalten und rücken zurück in die ungruppierte Liste.',
        ],
    ],
];
