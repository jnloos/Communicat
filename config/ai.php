<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider Names
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the AI providers below should be the
    | default for AI operations when no explicit provider is provided
    | for the operation. This should be any provider defined below.
    |
    */

    'default' => 'openai',

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | Below you may configure caching strategies for AI related operations
    | such as embedding generation. You are free to adjust these values
    | based on your application's available caching stores and needs.
    |
    */

    'caching' => [
        'embeddings' => [
            'cache' => false,
            'store' => env('CACHE_STORE', 'database'),
            'individually' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Providers
    |--------------------------------------------------------------------------
    |
    | Below are each of your AI providers defined for this application. Each
    | represents an AI provider and API key combination which can be used
    | to perform tasks like text, image, and audio creation via agents.
    |
    */

    'providers' => [
        'openai' => [
            'driver' => 'openai',
            'key' => env('OPENAI_API_KEY'),
            'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
            'store' => env('OPENAI_STORE', true),
        ],

        'anthropic' => [
            'driver' => 'anthropic',
            'key' => env('ANTHROPIC_API_KEY'),
            'url' => env('ANTHROPIC_URL', 'https://api.anthropic.com/v1'),
        ],

        'gemini' => [
            'driver' => 'gemini',
            'key' => env('GEMINI_API_KEY'),
            'url' => env('GEMINI_URL', 'https://generativelanguage.googleapis.com/v1beta/'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Study Model Registry (not part of the AI SDK's config schema)
    |--------------------------------------------------------------------------
    |
    | The two keys below belong to this application, not to laravel/ai: they
    | are this study's registry of selectable models and the key new projects
    | are preselected with. A project uses exactly one of them for every call
    | it makes (think, selector, speak, summarize), so a run stays homogeneous.
    | Do not fold "default_model" into the SDK's "default" above — that one
    | names a provider, this one a key from the registry below.
    |
    */

    'default_model' => env('LLM_DEFAULT_MODEL', 'openai-gpt-5'),

    /*
    | Selectable models: one entry per model, never per provider. The key names the
    | model, the label is what the dropdown shows, and "model" is a literal model id —
    | not an env() placeholder, so a stored key always means the same model and an old
    | run_config stays readable. Adding a model is one line here; the provider dropdown
    | groups the entries by their "provider" on its own (see ModelConfig::providers()).
    |
    | temperature = null means: do not send the parameter. Current Anthropic models and
    | OpenAI reasoning models reject it. reasoning_effort = null means: provider default.
    */
    'models' => [
        'openai-gpt-5' => [
            'label' => 'GPT-5',
            'provider' => 'openai',
            'model' => 'gpt-5',
            'max_output_tokens' => 8000,
            'temperature' => null,
            'reasoning_effort' => env('OPENAI_REASONING_EFFORT', 'low'),
        ],
        'anthropic-opus-5' => [
            'label' => 'Claude Opus 5',
            'provider' => 'anthropic',
            'model' => 'claude-opus-5',
            'max_output_tokens' => 8000,
            'temperature' => null,
            'reasoning_effort' => env('ANTHROPIC_REASONING_EFFORT', 'low'),
        ],
        'gemini-2.5-pro' => [
            'label' => 'Gemini 2.5 Pro',
            'provider' => 'gemini',
            'model' => 'gemini-2.5-pro',
            'max_output_tokens' => 8000,
            'temperature' => null,
            // Must stay null: Gemini has no effort levels (only a thinkingBudget token
            // count with no verified mapping to one). StudyAgent throws if this is
            // ever set to anything else.
            'reasoning_effort' => null,
        ],
    ],

];
