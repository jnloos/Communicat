<?php

/*
|--------------------------------------------------------------------------
| Experten: Liste, Filter, Editor, Gedächtnis-Flyout
|--------------------------------------------------------------------------
*/

return [
    'page_title' => 'Experten',

    'filter' => [
        'search_placeholder' => 'Experten nach Name, Beruf oder Beschreibung suchen…',
    ],

    'list' => [
        'heading' => 'Experten bearbeiten',
        'count' => ':count Experte|:count Experten',
        'create' => 'Experte erstellen',
        'no_matches' => 'Keine Experten entsprechen den aktuellen Filtern.',
        'empty' => 'Noch keine Experten vorhanden.',
    ],

    'editor' => [
        'create' => 'Experte erstellen',
        'update' => 'Experte aktualisieren',
        'subheading' => 'Legen Sie die Identität dieses Experten fest.',
        'change_avatar' => 'Klicken, um den Avatar zu ändern',
        'uploading' => 'Wird hochgeladen…',
        'job' => 'Beruf',
        'description_help' => 'Wird in der UI angezeigt und ist der Persona-Kern im Prompt.',
        'delete' => 'Experte löschen',
    ],

    'memory' => [
        'heading' => 'Gedächtnis zur Diskussion',
        'empty' => 'Noch kein Gedächtnis vorhanden. :name hat sich bislang keine Notizen gemacht.',
        'about_user' => 'Über den Nutzer',
        'about' => 'Über :name',
        'thoughts_of' => 'Gedanken von :name',
        'open_questions' => 'Offene Fragen',
        'last_state' => 'Letzter Gesprächsstand',
        'legacy_format' => 'Format wird beim nächsten Update aktualisiert.',
        'none_selected' => 'Kein Experte ausgewählt.',
    ],
];
