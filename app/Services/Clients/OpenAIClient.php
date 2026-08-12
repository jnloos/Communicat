<?php

namespace App\Services\Clients;

use App\Models\PromptLog;
use Illuminate\Support\Facades\Concurrency;
use OpenAI;
use OpenAI\Client;

class OpenAIClient
{
    protected Client $client;

    protected string $modelFast;

    protected string $modelSlow;

    protected static ?int $jobLogId = null;

    /** Rendered system framing, memoized per instance (the blade has no variables). */
    protected ?string $systemInstructionsCache = null;

    public function __construct()
    {
        $this->modelFast = config('apis.openai.model_fast');
        $this->modelSlow = config('apis.openai.model_slow');
        $this->client = OpenAI::client(config('apis.openai.api_key'));
    }

    /**
     * System-level framing applied to every call, rendered from prompts.system.
     * Frames the academic-simulation context so legitimate but politically/
     * ethically charged debate topics aren't refused — NOT a policy bypass; the
     * model still follows OpenAI policy, it just knows the personas are fictional.
     */
    protected function systemInstructions(): string
    {
        return $this->systemInstructionsCache ??= trim(view('prompts.system')->render());
    }

    public static function bindJobLog(?int $jobLogId): void
    {
        self::$jobLogId = $jobLogId;
    }

    /**
     * @param  array<string, mixed>|null  $schema  JSON schema the answer must satisfy
     *                                             (see ResponseSchemas). Null = free text.
     */
    public function send(
        string $prompt,
        ?string $model = null,
        string $label = '',
        ?array $schema = null,
        ?int $maxOutputTokens = null,
        ?string $reasoningEffort = null,
    ): string {
        $model = $model ?? $this->modelFast;
        $start = microtime(true);

        // outputText is nullable: a reasoning model that spends its whole token
        // budget on hidden reasoning returns a response with no text at all.
        $response = (string) ($this->client->responses()->create([
            'model' => $model,
            'instructions' => $this->systemInstructions(),
            'input' => $prompt,
        ] + $this->formatOptions($schema)
          + ($maxOutputTokens !== null ? ['max_output_tokens' => $maxOutputTokens] : [])
          + $this->reasoningOptions($model, $label, $reasoningEffort))->outputText ?? '');

        $this->logCall($label, $model, $prompt, $response, $start);

        return $response;
    }

    /**
     * @param  array<string, mixed>|null  $schema
     * @param  int|null  $maxOutputTokens  hard ceiling; the prompt's own length
     *                                     rules are routinely ignored, so short
     *                                     turns need an actual limit
     */
    public function sendFast(
        string $prompt,
        string $label = '',
        ?array $schema = null,
        ?int $maxOutputTokens = null,
        ?string $reasoningEffort = null,
    ): string {
        return $this->send($prompt, $this->modelFast, $label, $schema, $maxOutputTokens, $reasoningEffort);
    }

    /**
     * @param  array<string, mixed>|null  $schema
     */
    public function sendSlow(string $prompt, string $label = '', ?array $schema = null): string
    {
        return $this->send($prompt, $this->modelSlow, $label, $schema);
    }

    /**
     * Structured-output payload. With a schema attached the provider guarantees
     * a response matching it, so missing keys and invented enum values stop
     * reaching the parsers at all — the text fallbacks in ModeratorService
     * become genuine exception paths instead of routine ones.
     *
     * @param  array<string, mixed>|null  $schema
     * @return array<string, mixed>
     */
    protected function formatOptions(?array $schema): array
    {
        if ($schema === null) {
            return [];
        }

        return ['text' => ['format' => $schema]];
    }

    public function sendMany(array $prompts, ?string $model = null, string $label = ''): array
    {
        $apiKey = config('apis.openai.api_key');
        $model = $model ?? $this->modelFast;

        $keys = array_keys($prompts);
        // Render once here so the concurrency closures capture a plain string.
        $instructions = $this->systemInstructions();
        $reasoning = $this->reasoningOptions($model, $label);
        $tasks = [];
        foreach ($prompts as $prompt) {
            $tasks[] = static function () use ($apiKey, $model, $prompt, $instructions, $reasoning) {
                $client = OpenAI::client($apiKey);
                $start = microtime(true);
                $text = $client->responses()->create([
                    'model' => $model,
                    'instructions' => $instructions,
                    'input' => $prompt,
                ] + $reasoning)->outputText;

                return ['response' => $text, 'latency_ms' => (int) round((microtime(true) - $start) * 1000)];
            };
        }

        $raw = Concurrency::run($tasks);
        $results = [];
        $promptArr = array_values($prompts);

        foreach ($keys as $i => $key) {
            $results[$key] = $raw[$i]['response'];
            $entryLabel = $label !== '' ? "{$label}:{$key}" : (string) $key;
            $this->recordCall($entryLabel, $model, $promptArr[$i], $raw[$i]['response'], $raw[$i]['latency_ms']);
        }

        return $results;
    }

    public function sendManyFast(array $prompts, string $label = ''): array
    {
        return $this->sendMany($prompts, $this->modelFast, $label);
    }

    public function sendManySlow(array $prompts, string $label = ''): array
    {
        return $this->sendMany($prompts, $this->modelSlow, $label);
    }

    /**
     * gpt-5* are reasoning models and default to "medium" reasoning effort,
     * which dominated wall-clock latency (10-30s per call for a few hundred
     * chars of output). Cap the effort per call-label prefix via config
     * (apis.openai.reasoning_effort); non-reasoning models must not receive
     * the parameter at all, so anything else returns no options.
     */
    protected function reasoningOptions(string $model, string $label, ?string $override = null): array
    {
        if (! str_starts_with($model, 'gpt-5')) {
            return [];
        }

        // An explicit override wins: a token-capped call must not spend its
        // budget on reasoning, or nothing is left for the answer.
        if ($override !== null) {
            return ['reasoning' => ['effort' => $override]];
        }

        foreach (config('apis.openai.reasoning_effort', []) as $prefix => $effort) {
            if ($effort && str_starts_with($label, $prefix)) {
                return ['reasoning' => ['effort' => $effort]];
            }
        }

        return [];
    }

    protected function logCall(string $label, string $model, string $prompt, string $response, float $start): void
    {
        $this->recordCall($label, $model, $prompt, $response, (int) round((microtime(true) - $start) * 1000));
    }

    protected function recordCall(string $label, string $model, string $prompt, string $response, int $latencyMs): void
    {
        if (self::$jobLogId === null) {
            return;
        }

        try {
            PromptLog::create([
                'job_log_id' => self::$jobLogId,
                'label' => $label !== '' ? $label : null,
                'model' => $model,
                'prompt' => $prompt,
                'response' => $response,
                'latency_ms' => $latencyMs,
            ]);
        } catch (\Throwable) {
            // Never let debug logging fail the pipeline.
        }
    }
}
