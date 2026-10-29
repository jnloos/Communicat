<?php

/*
|--------------------------------------------------------------------------
| Projekte: Anlegen, Bearbeiten, Import/Export, Teilnehmer-Dialog
|--------------------------------------------------------------------------
*/

return [
    'page_title' => 'Projekte',

    'groups' => [
        'ai_pipeline' => 'AI Pipeline',
        'attributes' => 'Attribute',
        'reduction' => 'Reduktion',
    ],

    'providers' => [
        'openai' => 'OpenAI',
        'anthropic' => 'Anthropic',
        'gemini' => 'Google Gemini',
        'omniroute' => 'OmniRoute',
    ],

    'fields' => [
        'title_help' => 'Geben Sie einen kurzen, gut wiedererkennbaren Projekttitel ein.',
        'description_help' => 'Das Thema, über das die Experten diskutieren. Kurz und konkret, sie lesen es wörtlich.',
        'provider' => 'Anbieter',
        'provider_help' => 'Bestimmt, welche Modelle zur Auswahl stehen. Gespeichert wird nur das Modell.',
        'pipeline' => 'Pipeline',
        'model' => 'Modell',
        'model_help' => 'Alle Agenten dieses Projekts laufen auf diesem einen Modell.',
        'summarize_threshold' => 'Verdichtungsschwelle',
        'summarize_threshold_help' => 'Sobald so viele Nachrichten noch nicht verdichtet sind, läuft die Zusammenfassung.',
        'summarize_oldest' => 'Zu verdichtende Nachrichten',
        'summarize_oldest_help' => 'So viele der ältesten Nachrichten wandern dann in das Langzeitgedächtnis; sie muss kleiner als die Schwelle sein.',
        'attribute_visibility_locked' => 'Die Diskussion läuft bereits: Die Sichtbarkeit der Attribute lässt sich nicht mehr ändern.',
        'run_started_help' => 'Die Diskussion läuft bereits. Eine Änderung wird im run_config-Snapshot mit der Turn-Nummer festgehalten.',
    ],

    'attribute_visibility' => [
        'none' => 'Nicht gezeigt',
        'own' => 'Nur sich selbst',
        'all' => 'Alle',
    ],

    'attributes' => [
        'shown_to' => ':name: gezeigt für',
        'intro' => 'Was die Prompts von jedem Attribut verraten.',
        'none_defined' => 'Es sind noch keine Attribute definiert. Admins legen sie unter :link an.',
        'settings_link' => 'Einstellungen → Attribute',
    ],

    'create' => [
        'heading' => 'Projekt erstellen',
        'subheading' => 'Legen Sie das Thema fest, das die Experten diskutieren sollen.',
        'submit' => 'Projekt anlegen',
    ],

    'edit' => [
        'heading' => 'Projekt bearbeiten',
        'subheading' => 'Änderungen gelten ab dem nächsten Expertenbeitrag.',
        'submit' => 'Projekt aktualisieren',
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
