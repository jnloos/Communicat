<?php

/*
|--------------------------------------------------------------------------
| Projekte: Anlegen, Bearbeiten, Import/Export, Teilnehmer-Dialog
|--------------------------------------------------------------------------
*/

return [
    'page_title' => 'Projekte',

    'fields' => [
        'title_help' => 'Geben Sie einen kurzen, gut wiedererkennbaren Projekttitel ein.',
        'description_help' => 'Beschreiben Sie das Projekt klar und knapp. Diese Beschreibung wird von KI-Systemen genutzt – sie sollte daher leicht verständlich sein und die Kernidee präzise erfassen.',
        'memory_reduction' => 'Gedächtnisreduktion',
        'memory_reduction_help' => 'Diese Einstellung steuert, wie viele Nachrichten an das LLM gesendet werden. Eine hohe Reduktion spart Tokens, kann aber auch die Qualität der Diskussion mindern.',
        'reduction' => [
            'high' => 'Hoch',
            'standard' => 'Standard',
            'low' => 'Niedrig',
        ],
    ],

    'create' => [
        'heading' => 'Projekt erstellen',
        'subheading' => 'Legen Sie das Thema fest, das die Experten diskutieren sollen.',
        'submit' => 'Diskussion starten',
    ],

    'import' => [
        'heading' => 'Aus Datei erstellen',
        'description' => 'Laden Sie einen JSON-Export hoch, um eine vollständige Kopie als neues Projekt anzulegen.',
        'dropzone_heading' => 'JSON-Export hierher ziehen oder zum Auswählen klicken',
        'dropzone_text' => 'JSON, max. 20 MB',
        'remove_file' => 'Datei entfernen',
        'submit' => 'Aus Datei erstellen',
        'creating' => 'Wird erstellt…',
        'invalid_file' => 'Ungültige Exportdatei.',
        'missing_experts' => 'Einige Experten existieren nicht mehr und wurden übersprungen.',
        'untitled' => 'Unbenanntes Projekt',
        'copy_suffix' => '(Kopie)',
    ],

    'edit' => [
        'heading' => 'Projekt bearbeiten',
        'subheading' => 'Änderungen gelten ab dem nächsten Expertenbeitrag.',
        'submit' => 'Projekt aktualisieren',
        'export_json' => 'Als JSON exportieren',
        'delete' => 'Projekt löschen',
    ],

    'contributors' => [
        'heading' => 'Teilnehmende auswählen',
        'subheading' => 'Wählen Sie bis zu einen Experten aus und laden Sie andere Nutzer ein.|Wählen Sie bis zu :count Experten aus und laden Sie andere Nutzer ein.',
        'tab_experts' => 'Experten',
        'tab_users' => 'Nutzer',
        'limit_reached' => 'Limit erreicht: maximal ein Experte pro Projekt.|Limit erreicht: maximal :count Experten pro Projekt.',
        'limit_warning' => 'Maximal ein Experte pro Projekt.|Maximal :count Experten pro Projekt.',
        'no_expert_matches' => 'Keine Experten entsprechen den aktuellen Filtern.',
        'search_users' => 'Nutzer nach Name oder E-Mail suchen…',
        'no_user_matches' => 'Keine Nutzer entsprechen den aktuellen Filtern.',
    ],
];
