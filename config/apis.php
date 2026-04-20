<?php
return [
    'openai' => [
        'api_key'    => env('OPENAI_API_KEY'),
        'model_fast' => env('OPENAI_MODEL_FAST', 'gpt-5-nano-2025-08-07'),
        'model_slow' => env('OPENAI_MODEL_SLOW', 'gpt-4o'),
    ],
];
