<?php

/*
|--------------------------------------------------------------------------
| Experten: Liste, Filter, Editor, Gedächtnis-Flyout
|--------------------------------------------------------------------------
*/

return [
    'page_title' => 'Experten',

    'filter' => [
        'search_placeholder' => 'Experten nach Name, Rolle oder Beschreibung suchen…',
        'tags' => 'Tags',
        'tags_active' => 'Tags (:count)',
        'button' => 'Experten nach Tag filtern',
        'button_active' => 'Experten nach Tag filtern (:count aktiv)',
        'modes' => [
            'merge' => 'Experten, die irgendeinen gewählten Wert haben',
            'cut' => 'Experten, die jede gewählte Kategorie erfüllen',
        ],
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
        'role' => 'Rolle',
        'description_help' => 'Wird in der UI angezeigt und ist der Persona-Kern im Prompt.',
        'delete' => 'Experte löschen',
        'view' => 'Experte',
        'read_only' => 'Nur Administratoren ändern Experten.',
    ],

    'attributes' => [
        'single_line' => 'Ein Wert muss einzeilig sein.',
        'empty_hint' => 'Leer = in keinem Prompt erwähnt.',
        'value_placeholder' => 'Wert',
        'not_set' => 'nicht gesetzt',
        'persona_reads' => 'Persona liest:',
        'heading' => 'Attribute',
        'help' => 'Für das Modell sichtbar, anders als Tags. Ob und für wen sie gezeigt werden, wird pro Projekt festgelegt.',
    ],

    'memory' => [
        'heading' => 'Gedächtnis zur Diskussion',
        'empty' => 'Noch kein Gedächtnis vorhanden. :name hat sich bislang keine Notizen gemacht.',
        'none_selected' => 'Kein Experte ausgewählt.',
    ],
];
