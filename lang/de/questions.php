<?php

/*
|--------------------------------------------------------------------------
| Statistik-Seite: eine Frage über viele Läufe, in fester Form beantwortet
|--------------------------------------------------------------------------
*/

return [
    'heading' => 'Statistik',
    'subheading' => 'Stelle eine Frage über viele Läufe. Jeder Punkt ist ein Lauf.',
    'none_open' => 'Wähle links eine Frage, starte mit einer Vorlage oder stelle eine neue.',

    'list' => [
        'questions' => 'Meine Fragen',
        'templates' => 'Vorlagen',
        'create' => 'Neue Frage',
        'empty' => 'Noch keine Frage.',
        'no_templates' => 'Noch keine Vorlage. Speichere eine Frage als Vorlage, um sie wiederzuverwenden.',
    ],

    'actions' => [
        'rename' => 'Umbenennen',
        'duplicate' => 'Duplizieren',
        'save_as_template' => 'Als Vorlage speichern',
        'delete' => 'Löschen',
    ],

    'name' => [
        'rename' => 'Umbenennen',
        'field' => 'Name',
        'hint' => 'Leer lassen, dann heißt sie wie ihr Satz.',
    ],

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
        'all_turns' => 'allen Turns',
        'turns' => 'Turns :from bis :to',
        'from_turn' => 'ab Turn :from',
        'beyond_shortest_run' => 'ab Turn :from, länger als der kürzeste Lauf mit :count Turns',
        'over' => 'über',
        'name_compared_by' => 'nach :dimension',
        'name_split_by' => 'je :dimension',
        'deleted' => 'gelöschte Kategorie',
        'deleted_value' => 'gelöschter Wert',
    ],

    'controls' => [
        'metric' => 'Messgröße',
        'expert_name' => 'Experte nach Name',
        'any_expert' => 'Jeder Experte',
        'aggregate' => 'Gruppe zählen',
        'pipelines' => 'Pipelines',
        'models' => 'Modelle',
        'turn_from' => 'Ab Turn',
        'turn_to' => 'Bis Turn',
        'turns_hint' => '{1}Die obere Grenze ist höchstens der kürzeste ausgewählte Lauf, er hat :count Turn.|[0,*]Die obere Grenze ist höchstens der kürzeste ausgewählte Lauf, er hat :count Turns.',
        'compare_by' => 'Verglichen nach',
        'split_by' => 'Aufgeteilt nach',
        'none' => 'Nichts',
        'people_hint' => 'Mehrere Tags: jede Kategorie muss passen, innerhalb einer genügt ein Wert.',
        'gini_hint' => 'Gini beschreibt den ganzen Lauf und zählt daher alle.',
        'curve' => 'Kurve',
    ],

    'aggregates' => [
        'together' => 'zusammen',
        'per_head' => 'pro Kopf',
    ],

    'aggregate_hints' => [
        'together' => 'Die Anteile der Gruppe addiert. Alle Gruppen ergeben zusammen 100 %.',
        'per_head' => 'Der Anteil eines durchschnittlichen Mitglieds, damit verschieden große Gruppen fair vergleichbar sind.',
    ],

    'dimensions' => [
        'pipeline' => 'Pipeline',
        'model' => 'Modell',
        'persona' => 'Persona :category',
    ],

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
        'unsupported' => [
            'composition' => 'Nur wenn die Gruppen zusammen alle ergeben: ein Anteil, verglichen nach einem Persona-Tag, alle Personen, zusammen gezählt.',
            'trajectory' => 'Nur für Turn-Anteil und Wortanteil.',
        ],
    ],

    'curves' => [
        'cumulative' => 'Kumuliert',
        'rolling' => 'Gleitend',
    ],

    'result' => [
        'runs' => '{0}Kein Lauf passt.|{1}1 Lauf|[2,*]:count Läufe',
        'excluded' => '{1}1 Lauf hat hier keinen Wert: kein gesprochener Turn, niemand passt oder kein Wert in der verglichenen oder aufgeteilten Kategorie.|[2,*]:count Läufe haben hier keinen Wert: kein gesprochener Turn, niemand passt oder kein Wert in der verglichenen oder aufgeteilten Kategorie.',
        'dropped_tags' => '{1}1 Tag-Wert, den diese Frage nutzte, existiert nicht mehr und wurde entfernt. Eine Kategorie ohne verbleibenden Wert filtert weiter und trifft keinen Lauf: Chip öffnen und andere Werte wählen.|[2,*]:count Tag-Werte, die diese Frage nutzte, existieren nicht mehr und wurden entfernt. Eine Kategorie ohne verbleibenden Wert filtert weiter und trifft keinen Lauf: Chip öffnen und andere Werte wählen.',
        'n_of' => ':label · n',
        'empty' => 'Kein Lauf beantwortet diese Frage. Erweitere die Läufe oder die Personen.',
        'all' => 'Alle',
        'no_value' => 'ohne Angabe',
        'fair_share' => 'Gestrichelt: faire Erwartung, also was die Gruppe bekäme, wenn alle gleich viel sprächen.',
        'mean' => 'Mittelwert',
        'delta' => ':to minus :from: :delta',
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
