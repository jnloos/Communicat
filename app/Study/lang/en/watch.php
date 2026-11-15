<?php

// Study-only, removable: see app/Study/README.md. Texts of the monitoring's messages and /status.
return [
    'never' => 'never',
    'reminder' => 'Still active (since :since): :title',
    'recovered' => ':what resolved after :duration',
    'kind' => [
        'standstill' => 'Standstill',
        'paused' => 'Pause',
        'wave_finished' => 'Wave finished',
        'report' => 'Status report',
        'discord_rejected' => 'Discord',
        'manual' => 'Admin',
    ],
    'standstill' => [
        'title' => 'Standstill: no turn finished for :ago',
        'signal' => 'Worker signal :ago.',
        'alive' => 'The workers are alive, but the jobs fail.',
        'dead' => 'The workers are probably dead.',
    ],
    'paused' => [
        'title' => 'Study paused (:source)',
        'body' => 'Since :since · :reason (:signal)',
    ],
    'wave_finished' => [
        'title' => 'All runs finished',
        'body' => ':total runs are done.',
    ],
    'report' => 'Status report',
    'discord_rejected' => 'Discord rejected (:status)',
    'source' => ['breaker' => 'circuit breaker', 'manual' => 'manual', 'unknown' => 'unknown'],
    'discord' => [
        'wrong_channel' => 'Only in the study channel.',
        'command_description' => 'State of the study',
    ],
    'status' => [
        'no_cells' => 'No study cells yet. Missing project tag categories: :categories.',
        'running' => 'running',
        'paused' => 'PAUSED since :since · :reason',
        'done' => ':done/:total finished · :started running · :open open · :given_up given up',
        'pace' => ':turns turns/h · time left ≈ :hours h · last turn :last · worker signal :worker · :queued jobs queued',
        'disruptions' => 'Disruptions (last hour)',
        'given_up' => 'Given up',
    ],
    'manual_paused' => 'Paused by :user',
    'manual_resumed' => 'Resumed by :user',
];
