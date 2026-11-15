<?php

// Study-only, removable: see app/Study/README.md. Texte der Überwachungsmeldungen und von /status.
return [
    'never' => 'nie',
    'reminder' => 'Weiterhin (seit :since): :title',
    'recovered' => ':what behoben nach :duration',
    'kind' => [
        'standstill' => 'Stillstand',
        'paused' => 'Pause',
        'wave_finished' => 'Welle fertig',
        'report' => 'Lagebericht',
        'discord_rejected' => 'Discord',
        'manual' => 'Admin',
    ],
    'standstill' => [
        'title' => 'Stillstand: seit :ago kein Turn fertig',
        'signal' => 'Worker-Signal :ago.',
        'alive' => 'Die Worker leben, aber die Jobs scheitern.',
        'dead' => 'Die Worker sind vermutlich tot.',
    ],
    'paused' => [
        'title' => 'Studie pausiert (:source)',
        'body' => 'Seit :since · :reason (:signal)',
    ],
    'wave_finished' => [
        'title' => 'Alle Läufe fertig',
        'body' => ':total Läufe sind fertig.',
    ],
    'report' => 'Lagebericht',
    'discord_rejected' => 'Discord abgelehnt (:status)',
    'source' => ['breaker' => 'Circuit Breaker', 'manual' => 'manuell', 'unknown' => 'unbekannt'],
    'discord' => [
        'wrong_channel' => 'Nur im Studienkanal.',
        'command_description' => 'Stand der Studie',
    ],
    'status' => [
        'no_cells' => 'Noch keine Studienzellen. Es fehlen die Projekt-Tag-Kategorien: :categories.',
        'running' => 'läuft',
        'paused' => 'PAUSIERT seit :since · :reason',
        'done' => ':done/:total fertig · :started laufen · :open offen · :given_up aufgegeben',
        'pace' => ':turns Turns/h · Restzeit ≈ :hours h · letzter Turn :last · Worker-Signal :worker · :queued Jobs in der Queue',
        'disruptions' => 'Störungen (letzte Stunde)',
        'given_up' => 'Aufgegeben',
    ],
    'manual_paused' => 'Pausiert von :user',
    'manual_resumed' => 'Fortgesetzt von :user',
];
