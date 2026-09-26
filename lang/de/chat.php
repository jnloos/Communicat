<?php

/*
|--------------------------------------------------------------------------
| Projekt-Chat: Kopfzeile, Nachrichten, Composer, Statusanzeige, Willkommen
|--------------------------------------------------------------------------
*/

return [
    'header' => [
        'project_settings' => 'Projekteinstellungen',
        'leave_project' => 'Projekt verlassen',
        'set_contributors' => 'Teilnehmende festlegen',
    ],

    'message' => [
        'addressed' => 'Angesprochen:',
        'show_memory' => 'Gedächtnis anzeigen: :name',
        'open_thoughts' => 'Gedanken öffnen',
    ],

    'controls' => [
        'run' => 'Expertendiskussion starten',
        'pause' => 'Expertendiskussion pausieren',
        'start_label' => 'Diskutieren',
        'pause_label' => 'Pause',
        'sound_off' => 'Nachrichtenton ausschalten',
        'sound_on' => 'Nachrichtenton einschalten',
        'debug' => 'Debug-Report dieses Gesprächs',
        'stats' => 'Statistik (kommt später)',
        'aria_label' => 'Steuerung der Diskussion',
        'busy_hint' => 'Ein anderer Vorgang läuft gerade. Bitte versuchen Sie es gleich noch einmal.',
        'waiting_hint' => 'Warte auf die aktuelle Expertennachricht…',
    ],

    'indicator' => [
        'writing' => ':names schreibt|:names schreiben',
        'next_in' => 'Nächster Beitrag in :seconds s',
        'next_soon' => 'Nächster Beitrag in Kürze',
    ],

    'welcome' => [
        'intro' => 'Diskussionslauf zum Thema **:title**.',
        'purpose' => 'Die ausgewählten Experten diskutieren das Thema abwechselnd. Jeder Turn wird protokolliert.',
        'start_hint' => 'Wählen Sie die Experten aus und starten Sie den Lauf über **:button**.',
        'observer_hint' => 'Sie lesen mit. Eigene Beiträge sind nicht möglich.',
    ],
];
