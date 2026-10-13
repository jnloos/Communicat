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
        'tag_unreadable' => 'Die Tag-Kategorie hat keinen Namen oder einen unbekannten Bereich.',
        'tag_values_added' => 'Werte ergänzt: :values',
        'tags_skipped' => 'Tags nicht gesetzt: :tags',
        'new_expert_id' => 'ID :old war vergeben, der Experte hat jetzt ID :new. Selektor-Signale und E-Tokens in seinen Logs nennen weiter :old.',
    ],
];
