<?php

return [
    'errors' => [
        'not_a_zip' => 'This file is not a bundle. Export one from this app and try again.',
        'no_manifest' => 'The bundle has no manifest.json. Export one from this app and try again.',
        'unexpected_entry' => 'The bundle contains an unexpected file: :name',
        'too_large' => 'The bundle is too large to import.',
        'unknown_schema' => 'The bundle was written by an incompatible version of this app.',
        'broken_entry' => 'The bundle file :name is not valid JSON.',
    ],
    'notes' => [
        'already_there' => 'Already in the database.',
        'no_uuid' => 'The entry has no uuid.',
        'tag_unreadable' => 'The tag category has no name or an unknown scope.',
        'tag_values_added' => 'Values added: :values',
        'tags_skipped' => 'Tags not set: :tags',
        'new_expert_id' => 'Id :old was taken, so the expert got id :new. Selector signals and E-tokens in its logs still name :old.',
        'unknown_pipeline' => 'Unknown pipeline :pipeline.',
        'unknown_model' => 'Unknown model :model.',
        'missing_experts' => 'Experts missing from the bundle and the database: :experts',
        'projects_filed' => '{0}No project filed.|{1}1 project filed.|[2,*]:count projects filed.',
        'template_as_question' => 'Imported as your own question: only admins keep templates.',
    ],

    'actions' => [
        'export' => 'Export',
    ],
];
