<?php

/*
|--------------------------------------------------------------------------
| Settings → Attributes
|--------------------------------------------------------------------------
*/

return [
    'heading' => 'Attributes',
    'subheading' => 'Facts about an expert (origin, gender, …) and how they read in a prompt.',
    'help' => 'Unlike :link, the models read attributes. Each project chooses per attribute who sees it: nobody, only that expert, or all experts and the selector.',
    'empty' => 'No attribute yet. Add the first one below.',

    'rows' => [
        'self' => 'Persona reads:',
        'roster' => 'Others read:',
    ],

    'usage' => '{0}not used|{1}1 expert|[2,*]:count experts',

    'fields' => [
        'name' => 'Name',
        'self_template' => 'Sentence about the persona',
        'roster_template' => 'Text in the participants list',
    ],

    'descriptions' => [
        'self_template' => 'What the expert reads about itself.',
        'roster_template' => 'What the others read in the participants list.',
    ],

    'placeholders' => [
        'name' => 'e.g. Origin',
        'self_template' => 'You grew up in :value.',
        'roster_template' => 'from :value',
    ],

    'dialog' => [
        'create' => 'New attribute',
        'edit' => 'Edit attribute',
        'subheading' => 'Both texts must contain :placeholder, which is replaced by the expert’s value.',
    ],

    'preview' => [
        'label' => 'Preview with the value',
        'example' => 'Owerri, Nigeria',
        'self' => 'The expert reads',
        'roster' => 'The others read',
    ],

    'actions' => [
        'add' => 'Add attribute',
        'edit' => 'Edit',
        'delete' => 'Delete',
        'drag' => 'Drag to reorder',
        'menu' => 'Actions for :name',
    ],

    'confirm' => [
        'title' => 'Delete attribute?',
        'action' => 'Delete attribute',
        'message' => '{0}The attribute “:name” is not used by any expert and will be deleted.|{1}The attribute “:name” will be deleted and its value is lost for 1 expert. Runs that have already started are not affected: their lines are frozen.|[2,*]The attribute “:name” will be deleted and its values are lost for :count experts. Runs that have already started are not affected: their lines are frozen.',
    ],

    'errors' => [
        'role_locked' => 'The Role attribute is part of the study and cannot be renamed or deleted.',
        'missing_value' => 'The :attribute must contain :placeholder.',
    ],
];
