<?php

return [
    'errors' => [
        'not_a_zip' => 'Diese Datei ist kein Bundle. Exportiere eines aus dieser App und versuche es erneut.',
        'no_manifest' => 'Dem Bundle fehlt die manifest.json. Exportiere eines aus dieser App und versuche es erneut.',
        'unexpected_entry' => 'Das Bundle enthält eine unerwartete Datei: :name',
        'too_large' => 'Das Bundle ist zu groß für einen Import.',
        'unknown_schema' => 'Das Bundle stammt aus einer inkompatiblen Version dieser App.',
        'broken_entry' => 'Die Datei :name im Bundle ist kein gültiges JSON.',
    ],
    'notes' => [
        'already_there' => 'Schon in der Datenbank.',
        'no_uuid' => 'Der Eintrag hat keine UUID.',
        'bad_uuid' => 'Der Eintrag hat keine gültige UUID.',
        'unreadable_entry' => 'Der Eintrag konnte nicht gelesen werden.',
        'tag_unreadable' => 'Die Tag-Kategorie hat keinen Namen oder einen unbekannten Bereich.',
        'tag_values_added' => 'Werte ergänzt: :values',
        'tags_skipped' => 'Tags nicht gesetzt: :tags',
        'new_expert_id' => 'ID :old war vergeben, der Experte hat jetzt ID :new. Die Selektor-Signale seiner Turns nennen jetzt :new, E-Tokens in geloggten Prompts und Antworten weiter :old.',
        'unknown_pipeline' => 'Unbekannte Pipeline :pipeline.',
        'unknown_model' => 'Unbekanntes Modell :model.',
        'missing_experts' => 'Experten fehlen in Bundle und Datenbank: :experts',
        'projects_filed' => '{0}Kein Projekt einsortiert.|{1}1 Projekt einsortiert.|[2,*]:count Projekte einsortiert.',
        'template_as_question' => 'Als eigene Frage importiert: Vorlagen behalten nur Admins.',
    ],

    'heading' => 'Import/Export',
    'subheading' => 'Sichere die Studie als Archiv oder hole Projekte und Experten aus einem herein.',
    'export' => [
        'heading' => 'Exportieren',
        'description' => 'Alle Projekte mit Turns und protokollierten Aufrufen, alle Experten, die Tags, deine Sidebar-Gruppen und deine Fragen als eine .zip. Prompt-Texte älter als 24 Stunden sind nicht mehr gespeichert und deshalb nicht enthalten. Ein Archiv über dem unten genannten Upload-Limit importierst du mit php artisan bundle:import.',
        'everything' => 'Alles exportieren',
        'building' => 'Export wird erstellt …',
    ],
    'import' => [
        'heading' => 'Importieren',
        'description' => 'Eine aus dieser App exportierte .zip: ein Projekt, ein Experte oder alles. Du siehst, was passieren würde, bevor etwas geschrieben wird. Projekte und Experten, die schon da sind, werden übersprungen.',
        'choose' => 'Bundle auswählen',
        'dropzone_heading' => 'Bundle hierher ziehen oder klicken',
        'dropzone_text' => 'Eine .zip bis :mb MB; größere mit php artisan bundle:import',
        'failed' => 'Das Bundle konnte nicht gelesen werden: :message',
        'checking' => 'Bundle wird geprüft …',
        'preview_heading' => 'Das würde importiert',
        'nothing_new' => 'Nichts Neues: alles in diesem Bundle ist schon da.',
        'done_heading' => 'Importiert',
        'done' => 'Import abgeschlossen.',
        'done_with_failures' => 'Import abgeschlossen, aber einige Einträge sind fehlgeschlagen.',
        'submit' => 'Importieren',
        'again' => 'Weitere Datei importieren',
        'empty' => 'Das Bundle ist leer.',
    ],
    'sections' => [
        'tags' => 'Tags',
        'experts' => 'Experten',
        'projects' => 'Projekte',
        'groups' => 'Gruppen',
        'questions' => 'Fragen',
    ],
    'outcomes' => [
        'created' => '{1}1 neu|[2,*]:count neu',
        'skipped' => '{1}1 übersprungen|[2,*]:count übersprungen',
        'failed' => '{1}1 fehlgeschlagen|[2,*]:count fehlgeschlagen',
    ],
    'outcome' => [
        'created' => 'neu',
        'skipped' => 'übersprungen',
        'failed' => 'fehlgeschlagen',
    ],

    'actions' => [
        'export' => 'Exportieren',
    ],
];
