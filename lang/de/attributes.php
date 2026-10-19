<?php

/*
|--------------------------------------------------------------------------
| Einstellungen → Attribute
|--------------------------------------------------------------------------
*/

return [
    'heading' => 'Attribute',
    'subheading' => 'Fakten über einen Experten (Herkunft, Geschlecht, …) und wie sie im Prompt klingen.',
    'help' => 'Anders als Tags können die Modelle Attribute lesen. Ob und wem sie gezeigt werden, wählt das Projekt: gar nicht, nur dem Experten selbst oder allen.',
    'empty' => 'Noch kein Attribut. Fügen Sie unten das erste hinzu.',

    'rows' => [
        'self' => 'Persona liest:',
        'roster' => 'Andere lesen:',
    ],

    'usage' => '{0}nicht genutzt|{1}1 Experte|[2,*]:count Experten',

    'fields' => [
        'name' => 'Name',
        'self_template' => 'Satz über die Persona',
        'roster_template' => 'Text in der Teilnehmerliste',
    ],

    'descriptions' => [
        'self_template' => 'Das liest der Experte über sich selbst.',
        'roster_template' => 'Das lesen die anderen hinter der Rolle des Experten.',
    ],

    'placeholders' => [
        'name' => 'z. B. Herkunft',
        'self_template' => 'You grew up in :value.',
        'roster_template' => 'from :value',
    ],

    'dialog' => [
        'create' => 'Neues Attribut',
        'edit' => 'Attribut bearbeiten',
        'subheading' => 'Beide Texte müssen :placeholder enthalten, das durch den Wert des Experten ersetzt wird.',
    ],

    'preview' => [
        'label' => 'Vorschau mit dem Wert',
        'example' => 'Owerri, Nigeria',
        'self' => 'Der Experte liest',
        'roster' => 'Die anderen lesen',
    ],

    'actions' => [
        'add' => 'Attribut hinzufügen',
        'edit' => 'Bearbeiten',
        'delete' => 'Löschen',
        'drag' => 'Zum Umsortieren ziehen',
        'menu' => 'Aktionen für :name',
    ],

    'confirm' => [
        'title' => 'Attribut löschen?',
        'action' => 'Attribut löschen',
        'message' => '{0}Das Attribut „:name“ wird von keinem Experten genutzt und wird gelöscht.|{1}Das Attribut „:name“ wird gelöscht, sein Wert geht bei 1 Experten verloren. Bereits gestartete Läufe bleiben unberührt: ihre Zeilen sind eingefroren.|[2,*]Das Attribut „:name“ wird gelöscht, seine Werte gehen bei :count Experten verloren. Bereits gestartete Läufe bleiben unberührt: ihre Zeilen sind eingefroren.',
    ],

    'errors' => [
        'missing_value' => ':attribute muss :placeholder enthalten.',
    ],
];
