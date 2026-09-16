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

    'composer' => [
        'placeholder' => 'Nachricht schreiben…',
        'send' => 'Nachricht senden',
        'run' => 'Expertendiskussion starten',
        'pause' => 'Expertendiskussion pausieren',
        'start_label' => 'Start',
        'pause_label' => 'Pause',
        'sound_off' => 'Nachrichtenton ausschalten',
        'sound_on' => 'Nachrichtenton einschalten',
        'busy_hint' => 'Ein anderer Vorgang läuft gerade. Bitte versuchen Sie es gleich noch einmal.',
        'waiting_hint' => 'Warte auf die aktuelle Expertennachricht…',
    ],

    'indicator' => [
        'writing' => ':names schreibt|:names schreiben',
        'next_in' => 'Nächster Beitrag in :seconds s',
        'next_soon' => 'Nächster Beitrag in Kürze',
    ],

    'welcome' => [
        'intro' => 'Willkommen bei Communicat. Dies ist die Diskussion mit dem Titel **:title**.',
        'purpose' => 'Nutzen Sie diesen Chat, um das Thema mit Ihren KI-Experten und anderen Nutzern zu diskutieren.',
        'start_hint' => 'Fügen Sie mindestens einen Experten hinzu und starten Sie die Diskussion im Eingabefeld über **:button**.',
        'steer_hint' => 'Sie können auch eigene Nachrichten schreiben, um das Gespräch zu lenken.',
    ],
];
