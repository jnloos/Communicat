<?php

/*
|--------------------------------------------------------------------------
| Projektgruppen in der Seitenleiste
|--------------------------------------------------------------------------
*/

return [
    'empty' => 'Keine Projekte',

    'ungrouped' => 'Unsortierte Chats',

    'menus' => [
        'project' => 'Projektmenü',
        'group' => 'Gruppenmenü',
        'file_in' => 'Gruppe',
    ],

    'actions' => [
        'create_group' => 'Gruppe erstellen',
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
