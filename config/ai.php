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

            // Native structured output: the SDK sends the schema as
            // `output_config.format`, and StudyAgent::providerOptions() sends
            // only `thinking` for Anthropic, so nothing overwrites it. Checked
            // with one real call on 15.10.2026 (claude-opus-5, thinking
            // adaptive): the answer came back as valid JSON for the schema.
            // Native mode guarantees the shape; the synthetic-tool path
            // (false) only requests it, because Anthropic forbids forcing a
            // tool while extended thinking is on.
            'use_native_structured_output' => true,
        ],

        'gemini' => [
            'driver' => 'gemini',
            'key' => env('GEMINI_API_KEY'),
            'url' => env('GEMINI_URL', 'https://generativelanguage.googleapis.com/v1beta/'),
        ],

        // Exploration channel, not a replacement for 'openai': a self-hosted
        // OmniRoute gateway speaking Chat Completions through the SDK's
        // openai-compatible driver. Reachable only through the omniroute-* model
        // keys below, never as a default.
        'omniroute' => [
            'driver' => 'openai-compatible',
            'key' => env('OMNIROUTE_API_KEY'),
            'url' => env('OMNIROUTE_URL', 'http://127.0.0.1:20128/v1'),
            // The header name is taken from the OmniRoute docs and not yet
            // verified: check on the first real call that compression is off
            // (the prompt tokens in prompt_logs must match the OpenAI route).
            'headers' => ['x-omniroute-compression' => 'off'],
        ],

        // TypeSafe, the host of the Jev decision model (see 'jev' below). Not a team
        // model and never selectable in a project form: only the Jev* selectors reach
        // it, through the SDK's Classification API. The key is read from the
        // environment and never printed or logged.
        'typesafe' => [
            'driver' => 'typesafe',
            'key' => env('TYPESAFE_API_KEY'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Jev, the fixed decision model (not part of the AI SDK's config schema)
    |--------------------------------------------------------------------------
    |
    | The Jev pipelines (JevOrchestratorPipeline, JevQualityPipeline,
    | JevBlindQualityPipeline, JevScorePipeline) do not ask the team's model who
    | speaks but this one, the same for every team model.
    |
    | "provider" names the entry of 'providers' above that hosts it. "model" is a
    | literal, versioned id, never an alias such as jev-latest: a stored run must
    | always mean the same decision maker. Changing it starts a new series of
    | cells, like changing a team model; a run that has started reads the id,
    | provider and timeout frozen in its run_config.decision_model, not these.
    | "timeout" is the request timeout in seconds.
    |
    | "retries" is how many times a request that failed for a transient reason
    | (timeout, 429, 5xx) is sent again, with "backoff_ms" doubling each time, a
    | Retry-After header honoured, and no wait longer than "max_wait_ms". Rejected
    | requests (a wrong key, a format Jev refuses) are never retried: they stop the
    | run. "max_fallback_turns" is how many turns in a row may fall back on every
    | Jev request before the run is stopped (0 = never stop).
    |
    | "bid_resolution" is how JevScorePipeline compares Jev's bids: 'rounded' (the
    | expected level rounded to 1-5, read by HighestBidSelector; the default) or
    | 'unrounded' (JevBidSelector compares the expectations, so only exactly equal
    | scores tie). Change it between cells, never inside a series.
    |
    */
    'jev' => [
        'provider' => 'typesafe',
        'model' => 'jev-1.13.0',
        'timeout' => 60,
        'retries' => 2,
        'backoff_ms' => 500,
        'max_wait_ms' => 30000,
        'max_fallback_turns' => 3,
        'bid_resolution' => 'rounded',
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

    'default_model' => env('LLM_DEFAULT_MODEL', 'openai-gpt-5-mini'),

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
        // Not reachable with the key in .env as of 2026-09-29: the OpenAI project
        // it belongs to has no access to this model, and a run picked here fails
        // on its first turn rather than when the project is saved. The entry stays
        // because the first pilot ran on it and its run_config has to stay readable.
        'openai-gpt-5-mini' => [
            'label' => 'GPT-5 mini',
            'provider' => 'openai',
            'model' => 'gpt-5-mini',
            'max_output_tokens' => 8000,
            'temperature' => null,
            'reasoning_effort' => env('OPENAI_REASONING_EFFORT', 'low'),
        ],
        'openai-gpt-5-nano' => [
            'label' => 'GPT-5 nano',
            'provider' => 'openai',
            'model' => 'gpt-5-nano',
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
            // Anthropic exposes no effort parameter this SDK can set; see
            // StudyAgent::providerOptions(). Configuring one throws.
            'reasoning_effort' => null,
        ],
        'anthropic-haiku-4-5' => [
            'label' => 'Claude Haiku 4.5',
            'provider' => 'anthropic',
            'model' => 'claude-haiku-4-5-20251001',
            'max_output_tokens' => 8000,
            'temperature' => null,
            // No effort parameter, as for the other Anthropic entries.
            'reasoning_effort' => null,
            // The API refuses `thinking: adaptive` on this model (400), so no thinking is sent.
            'adaptive_thinking' => false,
        ],
        'gemini-3.8-flash' => [
            'label' => 'Gemini 3.8 Flash',
            'provider' => 'gemini',
            'model' => 'gemini-3.8-flash',
            'max_output_tokens' => 8000,
            'temperature' => null,
            // Must stay null: Gemini has no effort levels (only a thinkingBudget token
            // count with no verified mapping to one). StudyAgent throws if this is
            // ever set to anything else.
            'reasoning_effort' => null,
        ],
        // Direct model id on purpose, never `auto` or a combo: a combo may fall back to
        // another model and the run would no longer be one model. `cx/` is the gateway's
        // Codex (ChatGPT account) route; the ids are the ones of GET /v1/models of the
        // running instance (checked 19.10.2026). With that account gpt-5.5 and
        // gpt-5.3-codex-spark are refused upstream; luna, terra and sol answer.
        'omniroute-gpt-5.6-luna' => [
            'label' => 'GPT-5.6 Luna',
            'provider' => 'omniroute',
            'model' => 'cx/gpt-5.6-luna',
            'max_output_tokens' => 8000,
            'temperature' => null,
            'reasoning_effort' => env('OPENAI_REASONING_EFFORT', 'low'),
        ],
        'omniroute-gpt-5.6-terra' => [
            'label' => 'GPT-5.6 Terra',
            'provider' => 'omniroute',
            'model' => 'cx/gpt-5.6-terra',
            'max_output_tokens' => 8000,
            'temperature' => null,
            'reasoning_effort' => env('OPENAI_REASONING_EFFORT', 'low'),
        ],
        'omniroute-gpt-5.6-sol' => [
            'label' => 'GPT-5.6 Sol',
            'provider' => 'omniroute',
            'model' => 'cx/gpt-5.6-sol',
            'max_output_tokens' => 8000,
            'temperature' => null,
            'reasoning_effort' => env('OPENAI_REASONING_EFFORT', 'low'),
        ],
        // Claude through the gateway's Claude Code route (`cc/`), direct ids again. Each answered a
        // real call on 19.10.2026. The route adds a small fixed prefix to every prompt (about 18 prompt
        // tokens on a ten-token message), the same for all cells.
        'omniroute-claude-opus-5' => [
            'label' => 'Claude Opus 5',
            'provider' => 'omniroute',
            'model' => 'cc/claude-opus-5',
            'max_output_tokens' => 8000,
            'temperature' => null,
            // The effort is part of the gateway's model name for Claude (-low, -high ...), so none is sent.
            'reasoning_effort' => null,
        ],
        'omniroute-claude-sonnet-5' => [
            'label' => 'Claude Sonnet 5',
            'provider' => 'omniroute',
            'model' => 'cc/claude-sonnet-5',
            'max_output_tokens' => 8000,
            'temperature' => null,
            // The effort is part of the gateway's model name for Claude (-low, -high ...), so none is sent.
            'reasoning_effort' => null,
        ],
        'omniroute-claude-fable-5' => [
            'label' => 'Claude Fable 5',
            'provider' => 'omniroute',
            'model' => 'cc/claude-fable-5',
            'max_output_tokens' => 8000,
            'temperature' => null,
            // The effort is part of the gateway's model name for Claude (-low, -high ...), so none is sent.
            'reasoning_effort' => null,
        ],
        'omniroute-claude-haiku-4-5' => [
            'label' => 'Claude Haiku 4.5',
            'provider' => 'omniroute',
            'model' => 'cc/claude-haiku-4-5-20251001',
            'max_output_tokens' => 8000,
            'temperature' => null,
            // The effort is part of the gateway's model name for Claude (-low, -high ...), so none is sent.
            'reasoning_effort' => null,
        ],
    ],

];
