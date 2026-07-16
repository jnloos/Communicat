<?php

return [
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model_fast' => env('OPENAI_MODEL_FAST', 'gpt-5-nano-2025-08-07'),
        'model_slow' => env('OPENAI_MODEL_SLOW', 'gpt-4o'),
        // Reasoning-effort caps keyed by call-label prefix, applied only to
        // reasoning models (gpt-5*). Without a cap they default to "medium"
        // and burn 10-30s of hidden reasoning per call. Moderator route/select
        // are structured selection decisions → minimal; speak keeps low effort
        // as headroom for persona quality. Unlisted labels keep the API default.
        'reasoning_effort' => [
            'moderator' => env('OPENAI_REASONING_MODERATOR', 'minimal'),
            'speak' => env('OPENAI_REASONING_SPEAK', 'low'),
        ],
    ],
    'elevenlabs' => [
        'api_key' => env('ELEVENLABS_API_KEY'),
        'model' => env('ELEVENLABS_MODEL', 'eleven_multilingual_v2'),
        'default_voice' => env('ELEVENLABS_DEFAULT_VOICE', 'EXAVITQu4vr4xnSDxMaL'),
    ],
];
