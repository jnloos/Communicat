<?php

use App\Llm\Providers\AnthropicClient;
use App\Llm\Providers\GeminiClient;
use App\Llm\Providers\OpenAiClient;

return [

    // Schlüssel des Modells, das neue Projekte vorausgewählt bekommen.
    'default' => env('LLM_DEFAULT_MODEL', 'openai'),

    /*
    | Wählbare Modelle. Ein Projekt nutzt genau eines davon für alle Aufrufe
    | (Think, Selector, Speak, Summarize). temperature = null heißt: Parameter
    | nicht senden. Aktuelle Anthropic-Modelle und OpenAI-Reasoning-Modelle
    | lehnen ihn ab. reasoning_effort = null heißt: Anbieter-Default.
    */
    'models' => [
        'openai' => [
            'label' => 'OpenAI',
            'provider' => 'openai',
            'model' => env('OPENAI_MODEL', 'gpt-5'),
            'max_output_tokens' => 8000,
            'temperature' => null,
            'reasoning_effort' => env('OPENAI_REASONING_EFFORT', 'low'),
        ],
        'anthropic' => [
            'label' => 'Anthropic',
            'provider' => 'anthropic',
            'model' => env('ANTHROPIC_MODEL', 'claude-opus-5'),
            'max_output_tokens' => 8000,
            'temperature' => null,
            'reasoning_effort' => env('ANTHROPIC_REASONING_EFFORT', 'low'),
        ],
        'gemini' => [
            'label' => 'Google Gemini',
            'provider' => 'gemini',
            'model' => env('GEMINI_MODEL', 'gemini-2.5-pro'),
            'max_output_tokens' => 8000,
            'temperature' => null,
            // Must stay null: Gemini has no effort levels (only a thinkingBudget token
            // count with no verified mapping to one). GeminiClient::make() throws if
            // this is ever set to anything else.
            'reasoning_effort' => null,
        ],
    ],

    // Ein neuer Anbieter ist eine neue Adapter-Klasse plus eine Zeile hier.
    'providers' => [
        'openai' => OpenAiClient::class,
        'anthropic' => AnthropicClient::class,
        'gemini' => GeminiClient::class,
    ],

    'keys' => [
        'openai' => env('OPENAI_API_KEY'),
        'anthropic' => env('ANTHROPIC_API_KEY'),
        'gemini' => env('GEMINI_API_KEY'),
    ],
];
