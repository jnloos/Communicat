<?php

namespace App\Llm;

use InvalidArgumentException;

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
        $entry = config("llm.models.{$key}");

        if (! is_array($entry)) {
            throw new InvalidArgumentException("Unknown LLM model key [{$key}]. Check config/llm.php.");
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
