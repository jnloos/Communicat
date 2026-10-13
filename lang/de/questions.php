<?php

/*
|--------------------------------------------------------------------------
| Statistik-Seite: eine Frage über viele Läufe, beantwortet als Läufe, Anteile oder Kurven
|--------------------------------------------------------------------------
*/

return [
    'heading' => 'Statistik',
    'subheading' => 'Stelle eine Frage über viele Läufe. Jeder Punkt ist ein Lauf.',
    'none_open' => 'Wähle links eine Vorlage oder eine deiner Fragen, oder stelle eine neue.',

    'list' => [
        'questions' => 'Fragen',
        'templates' => 'Vorlagen',
        'create' => 'Neue Frage',
        'empty' => 'Noch keine Frage.',
        'no_templates' => 'Noch keine Vorlage.',
        'no_templates_admin' => 'Speichere eine Frage als Vorlage, um sie zu teilen.',
    ],

    'actions' => [
        'move_up' => 'Nach oben',
        'move_down' => 'Nach unten',
        'rename' => 'Umbenennen',
        'duplicate' => 'Duplizieren',
        'save_as_template' => 'Als Vorlage speichern',
        'copy_to_adjust' => 'Kopieren und anpassen',
        'delete' => 'Löschen',
    ],

    'name' => [
        'rename' => 'Umbenennen',
        'field' => 'Titel',
        'hint' => 'Leer lassen für keinen Titel.',
    ],

    'notes' => [
        'label' => 'Frage und Notizen',
        'placeholder' => 'Was willst du herausfinden, und welche Schlüsse ziehst du?',
    ],

    'untitled' => 'Unbenannte Frage',

    'copy_name' => 'Kopie von :name',

    'confirm' => [
        'delete' => [
            'title' => 'Frage löschen?',
            'message' => '„:name“ wird gelöscht. Projekte und Tags bleiben unverändert.',
        ],
        'delete_template' => [
            'title' => 'Vorlage löschen?',
            'message' => 'Die Vorlage „:name“ wird für alle gelöscht. Fragen, die daraus entstanden sind, bleiben.',
        ],
    ],

    'template' => [
        'without_groups' => 'Gruppen sind persönlich, deshalb nutzt die Vorlage sie nicht.',
        'view_only_notice' => 'Diese Vorlage ist nur zum Ansehen. Kopiere sie, um sie anzupassen.',
    ],

    'autosave' => [
        'saved' => 'Automatisch gespeichert',
        'saving' => 'Speichert …',
    ],

    'sentence' => [
        'of' => 'von',
        'in' => 'in',
        'compared_by' => 'verglichen nach',
        'split_by' => 'aufgeteilt nach',
        'everyone' => 'allen',
        'all_runs' => 'allen Läufen',
        'nothing' => 'nichts',
        'all_turns' => 'in allen Turns',
        'turns' => 'in den Turns :from bis :to',
        'from_turn' => 'ab Turn :from',
        'beyond_shortest_run' => 'ab Turn :from, länger als der kürzeste Lauf mit :count Turns',
        'of_people' => 'von :people',
        'in_projects' => 'in :projects',
        'compared_by_dimension' => 'verglichen nach :dimension',
        'split_by_dimension' => 'aufgeteilt nach :dimension',
        'deleted' => 'gelöschte Kategorie',
        'deleted_value' => 'gelöschter Wert',
        'deleted_group' => 'gelöschte Gruppe',
    ],

    'controls' => [
        'metric' => 'Messgröße',
        'expert_names' => 'Experten nach Name',
        'any_expert' => 'Jeder Experte',
        'aggregate' => 'Gruppe zählen',
        'pipelines' => 'Pipelines',
        'models' => 'Modelle',
        'groups' => 'Gruppen',
        'turn_from' => 'Ab Turn',
        'turn_to' => 'Bis Turn',
        'compare_by' => 'Verglichen nach',
        'baseline' => 'Bezug',
        'split_by' => 'Aufgeteilt nach',
        'none' => 'Nichts',
        'gini_hint' => 'Gini beschreibt den ganzen Lauf und zählt daher alle.',
    ],

    'aggregates' => [
        'together' => 'zusammen',
        'per_head' => 'pro Kopf',
    ],

    'dimensions' => [
        'pipeline' => 'Pipeline',
        'model' => 'Modell',
        'group' => 'Gruppe',
    ],

    'sources' => [
        'project_tag' => 'Projekt',
        'persona_tag' => 'Experte',
        'run' => 'Lauf',
        'group' => 'Gruppe',
    ],

    /* A listed item names where it comes from in square brackets: "Pipeline [Run]". */
    'source_label' => ':name [:source]',

    'metrics' => [
        'turn_share' => 'Turn-Anteil',
        'word_share' => 'Wortanteil',
        'words_per_turn' => 'Wörter pro Turn',
        'turn_gini' => 'Gini der Turns',
        'word_gini' => 'Gini der Wörter',
    ],

    'forms' => [
        'points' => 'Läufe',
        'composition' => 'Anteile',
        'trajectory' => 'Über den Lauf',
    ],

    'form_hints' => [
        'points' => 'Jeder Lauf ein Punkt',
        'composition' => 'Gruppen als Teile von 100 %',
        'trajectory' => 'Mittlerer Anteil pro Turn',
    ],

    'curves' => [
        'cumulative' => 'Kumuliert',
        'rolling' => 'Gleitend',
    ],

    'result' => [
        'dropped_tags' => '{1}1 Tag-Wert, den diese Frage nutzte, existiert nicht mehr und wurde entfernt. Eine Kategorie ohne verbleibenden Wert filtert weiter und trifft keinen Lauf: Chip öffnen und andere Werte wählen.|[2,*]:count Tag-Werte, die diese Frage nutzte, existieren nicht mehr und wurden entfernt. Eine Kategorie ohne verbleibenden Wert filtert weiter und trifft keinen Lauf: Chip öffnen und andere Werte wählen.',
        'n_of' => ':label, n',
        'fair_share' => 'Faire Erwartung',
        'fair_share_of' => ':label, faire Erwartung',
        'empty' => 'Kein Lauf beantwortet diese Frage. Erweitere die Läufe oder die Personen.',
        'all' => 'Alle',
        'no_value' => 'ohne Angabe',
        'mean' => 'Mittelwert',
        'delta' => 'Effektstärke: :delta',
        'too_short' => 'Eine Kurve braucht mindestens zwei gesprochene Turns.',
        'run_table' => '{1}Der Lauf hinter diesen Zahlen|[2,*]Die :count Läufe hinter diesen Zahlen',
        'columns' => [
            'run' => 'Lauf',
            'panel' => 'Panel',
            'group' => 'Gruppe',
            'value' => 'Wert',
        ],
    ],
];
