<?php

namespace App\Discussion\Values;

use InvalidArgumentException;
use Laravel\Ai\Enums\Lab;

final readonly class ModelConfig
{
    public function __construct(
        public string $key,
        public string $label,
        public string $provider,
        public string $model,
        public int $maxOutputTokens,
        public ?float $temperature = null,
        public ?string $reasoningEffort = null,
        public ?bool $nativeStructuredOutput = null,
    ) {}

    public static function fromConfig(string $key): self
    {
        // Array index, not dot notation: a model key may contain dots
        // (gemini-3.8-flash), and config() would read those as nesting.
        if (! self::exists($key)) {
            throw new InvalidArgumentException("Unknown LLM model key [{$key}]. Check config/ai.php.");
        }

        $entry = config('ai.models')[$key];

        return new self(
            key: $key,
            label: $entry['label'] ?? $key,
            provider: $entry['provider'],
            model: $entry['model'],
            maxOutputTokens: (int) ($entry['max_output_tokens'] ?? 8000),
            temperature: isset($entry['temperature']) ? (float) $entry['temperature'] : null,
            reasoningEffort: $entry['reasoning_effort'] ?? null,
            nativeStructuredOutput: self::nativeStructuredOutputOf($entry['provider']),
        );
    }

    /** Whether the registry has this model key. Array index, not dot notation, as in fromConfig(). */
    public static function exists(string $key): bool
    {
        return is_array(config('ai.models')[$key] ?? null);
    }

    /**
     * Whether the provider answers schemas natively (shape guaranteed) or through
     * the synthetic tool (shape only requested). A provider-level switch, so it
     * is read from `ai.providers`; null where the provider has no such switch.
     */
    private static function nativeStructuredOutputOf(string $provider): ?bool
    {
        $value = (config('ai.providers')[$provider] ?? [])['use_native_structured_output'] ?? null;

        return $value === null ? null : (bool) $value;
    }

    /**
     * The registry as the model dropdowns need it.
     *
     * @return array<string, string> model key → label
     */
    public static function options(): array
    {
        return collect(config('ai.models', []))
            ->map(fn (array $entry, string $key) => $entry['label'] ?? $key)
            ->all();
    }

    /**
     * The providers the registry actually offers, each exactly once, in registry order.
     * Labels are UI text and live in lang/{de,en}/projects.php.
     *
     * @return array<string, string> provider key → provider label
     */
    public static function providers(): array
    {
        return collect(config('ai.models', []))
            ->pluck('provider')
            ->unique()
            ->mapWithKeys(fn (string $provider) => [$provider => __("projects.providers.{$provider}")])
            ->all();
    }

    /**
     * The models of a single provider, as the dependent model dropdown needs them.
     *
     * @return array<string, string> model key → label
     */
    public static function optionsFor(string $provider): array
    {
        return collect(config('ai.models', []))
            ->filter(fn (array $entry) => ($entry['provider'] ?? null) === $provider)
            ->map(fn (array $entry, string $key) => $entry['label'] ?? $key)
            ->all();
    }

    public function lab(): Lab
    {
        return Lab::tryFrom($this->provider)
            ?? throw new InvalidArgumentException("Provider [{$this->provider}] is unknown to the AI SDK.");
    }

    /** Snapshot for prompt_logs.config and projects.run_config. */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'provider' => $this->provider,
            'model' => $this->model,
            'max_output_tokens' => $this->maxOutputTokens,
            'temperature' => $this->temperature,
            'reasoning_effort' => $this->reasoningEffort,
            'native_structured_output' => $this->nativeStructuredOutput,
        ];
    }
}
