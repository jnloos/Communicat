<?php

/*
|--------------------------------------------------------------------------
| Experten: Liste, Filter, Editor, Gedächtnis-Flyout
|--------------------------------------------------------------------------
*/

return [
    'page_title' => 'Experten',

    'filter' => [
        'search_placeholder' => 'Experten nach Name, Beschreibung oder Attribut suchen…',
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
        'description_help' => 'Wird in der UI angezeigt und ist der Persona-Kern im Prompt.',
        'delete' => 'Experte löschen',
        'view' => 'Experte',
        'read_only' => 'Nur Administratoren ändern Experten.',
    ],

    'confirm' => [
        'title' => 'Experte löschen?',
        'action' => 'Experte löschen',
        'message' => '{0}„:name“ sitzt in keinem Projekt und wird gelöscht. Das lässt sich nicht rückgängig machen.|{1}„:name“ sitzt in 1 Projekt und wird gelöscht. Seine Beiträge dort zählen nicht mehr zum Turn-Budget und zu den Metriken. Das lässt sich nicht rückgängig machen.|[2,*]„:name“ sitzt in :count Projekten und wird gelöscht. Seine Beiträge dort zählen nicht mehr zum Turn-Budget und zu den Metriken. Das lässt sich nicht rückgängig machen.',
    ],

    'attributes' => [
        'single_line' => 'Ein Wert muss einzeilig sein.',
        'value_placeholder' => 'Wert',
        'summary_none' => 'Nicht gesetzt',
        'not_set' => 'nicht gesetzt',
        'persona_reads' => 'Persona liest:',
        'heading' => 'Attribute',
        'help' => 'Die Modelle lesen diese; jedes Projekt legt fest, wer sie sieht. Leere Felder kommen in keinem Prompt vor.',
    ],

    'memory' => [
        'heading' => 'Gedächtnis zur Diskussion',
        'empty' => 'Noch kein Gedächtnis vorhanden. :name hat sich bislang keine Notizen gemacht.',
        'none_selected' => 'Kein Experte ausgewählt.',
    ],
];
