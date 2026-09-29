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

            // Anthropic's native structured output is sent as `output_config`,
            // which is the same key StudyAgent uses for the reasoning effort --
            // and provider options are merged last, so ours would silently
            // delete the schema. Turning it off makes the SDK request structure
            // through a synthetic tool instead, leaving output_config free.
            //
            // The cost: with `thinking` on, the gateway must use
            // tool_choice: auto (Anthropic forbids forcing a tool alongside
            // extended thinking), so structure is requested, not guaranteed. A
            // turn that comes back unstructured fails and is counted in the
            // failure rate the study reports anyway.
            'use_native_structured_output' => false,
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

    'default_model' => env('LLM_DEFAULT_MODEL', 'openai-gpt-5-nano'),

    /*
    | Selectable models: one entry per model, never per provider. The key names the
    | model, the label is what the dropdown shows, and "model" is a literal model id —
    | not an env() placeholder, so a stored key always means the same model and an old
    | run_config stays readable. Adding a model is one line here; the provider dropdown
    | groups the entries by their "provider" on its own (see ModelConfig::providers()).
    |
    | temperature = null means: do not send the parameter. Current Anthropic models and
    | OpenAI reasoning models reject it. reasoning_effort = null means: provider default.
    |
    | "price" is US dollars per million tokens, list price, and it is bookkeeping
    | only -- nothing in the discussion reads it. Two things to know before you
    | trust a figure derived from it: reasoning tokens are already part of
    | "tokens_out" in prompt_logs and are billed at the output rate, so they are
    | never added a second time; and a cached input token is cheaper than the rate
    | below, which the logs do not distinguish, so an estimate is an upper bound on
    | input. price = null means the model is unpriced and a run with it reports no
    | cost rather than a wrong one. Checked against the providers' public price
    | lists on 2026-09-27 -- reconcile against the invoice before quoting a figure.
    */
    'models' => [
        'openai-gpt-5' => [
            'label' => 'GPT-5',
            'provider' => 'openai',
            'model' => 'gpt-5',
            'price' => ['input' => 1.25, 'output' => 10.00],
            'max_output_tokens' => 8000,
            'temperature' => null,
            'reasoning_effort' => env('OPENAI_REASONING_EFFORT', 'low'),
        ],
        // Not reachable with the key in .env as of 2026-09-29: the OpenAI project
        // it belongs to has no access to this model, and a run picked here fails
        // on its first turn rather than when the project is saved. The entry stays
        // because the first pilot ran on it and its run_config has to stay readable.
        'openai-gpt-5-mini' => [
            'label' => 'GPT-5 mini',
            'provider' => 'openai',
            'model' => 'gpt-5-mini',
            'price' => ['input' => 0.25, 'output' => 2.00],
            'max_output_tokens' => 8000,
            'temperature' => null,
            'reasoning_effort' => env('OPENAI_REASONING_EFFORT', 'low'),
        ],
        'openai-gpt-5-nano' => [
            'label' => 'GPT-5 nano',
            'provider' => 'openai',
            'model' => 'gpt-5-nano',
            'price' => ['input' => 0.05, 'output' => 0.40],
            'max_output_tokens' => 8000,
            'temperature' => null,
            'reasoning_effort' => env('OPENAI_REASONING_EFFORT', 'low'),
        ],
        'anthropic-opus-5' => [
            'label' => 'Claude Opus 5',
            'provider' => 'anthropic',
            'model' => 'claude-opus-5',
            'price' => ['input' => 5.00, 'output' => 25.00],
            'max_output_tokens' => 8000,
            'temperature' => null,
            // Anthropic exposes no effort parameter this SDK can set; see
            // StudyAgent::providerOptions(). Configuring one throws.
            'reasoning_effort' => null,
        ],
        'gemini-3.8-flash' => [
            'label' => 'Gemini 3.8 Flash',
            'provider' => 'gemini',
            'model' => 'gemini-3.8-flash',
            'price' => ['input' => 0.30, 'output' => 2.50],
            'max_output_tokens' => 8000,
            'temperature' => null,
            // Must stay null: Gemini has no effort levels (only a thinkingBudget token
            // count with no verified mapping to one). StudyAgent throws if this is
            // ever set to anything else.
            'reasoning_effort' => null,
        ],
    ],

];
