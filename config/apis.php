<?php
return [
    'openai' => [
        'api_key'    => env('OPENAI_API_KEY'),
        'model_fast' => env('OPENAI_MODEL_FAST', 'gpt-5-nano-2025-08-07'),
        'model_slow' => env('OPENAI_MODEL_SLOW', 'gpt-4o'),
    ],
    'elevenlabs' => [
        'api_key' => env('ELEVENLABS_API_KEY'),
        'model' => env('ELEVENLABS_MODEL', 'eleven_multilingual_v2'),
        'default_voice' => env('ELEVENLABS_DEFAULT_VOICE', 'EXAVITQu4vr4xnSDxMaL'),
    ],
];
