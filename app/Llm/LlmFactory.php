<?php

namespace App\Llm;

use App\Models\Project;
use InvalidArgumentException;

class LlmFactory
{
    /** The project's model, with every call logged to prompt_logs. */
    public function forProject(Project $project): LlmClient
    {
        return new LoggingLlmClient($this->adapter($project->model));
    }

    /** The bare provider adapter for a model key from config/llm.php. */
    public function adapter(string $modelKey): LlmClient
    {
        $config = ModelConfig::fromConfig($modelKey);
        $adapter = config("llm.providers.{$config->provider}");

        if (! is_string($adapter) || ! is_subclass_of($adapter, LlmClient::class)) {
            throw new InvalidArgumentException("No LLM adapter registered for provider [{$config->provider}].");
        }

        return $adapter::make($config);
    }

    /** @return array<string, string> model key → label */
    public function options(): array
    {
        return collect(config('llm.models', []))
            ->map(fn (array $entry, string $key) => $entry['label'] ?? $key)
            ->all();
    }
}
