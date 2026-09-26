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
    ) {}

    public static function fromConfig(string $key): self
    {
        // Array index, not dot notation: a model key may contain dots
        // (gemini-3.8-flash), and config() would read those as nesting.
        $entry = config('ai.models')[$key] ?? null;

        if (! is_array($entry)) {
            throw new InvalidArgumentException("Unknown LLM model key [{$key}]. Check config/ai.php.");
        }

        return new self(
            key: $key,
            label: $entry['label'] ?? $key,
            provider: $entry['provider'],
            model: $entry['model'],
            maxOutputTokens: (int) ($entry['max_output_tokens'] ?? 8000),
            temperature: isset($entry['temperature']) ? (float) $entry['temperature'] : null,
            reasoningEffort: $entry['reasoning_effort'] ?? null,
        );
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
        ];
    }
}
