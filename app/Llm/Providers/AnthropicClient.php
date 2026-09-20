<?php

namespace App\Llm\Providers;

use Anthropic\Client;
use App\Llm\LlmClient;
use App\Llm\LlmException;
use App\Llm\LlmRequest;
use App\Llm\LlmResponse;
use App\Llm\ModelConfig;
use BackedEnum;
use Throwable;

final class AnthropicClient implements LlmClient
{
    use CompletesConcurrently;

    public function __construct(
        private readonly ModelConfig $config,
        private readonly Client $client,
    ) {}

    public static function make(ModelConfig $config): static
    {
        return new self($config, new Client(apiKey: (string) config('llm.keys.anthropic')));
    }

    public function config(): ModelConfig
    {
        return $this->config;
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $start = microtime(true);

        try {
            $message = $this->client->messages->create(...$this->buildParams($request));
        } catch (Throwable $e) {
            throw new LlmException($e->getMessage(), LlmException::KIND_API, $e);
        }

        return $this->mapResponse($message, (int) round((microtime(true) - $start) * 1000));
    }

    /**
     * Keys are the SDK's camelCase named arguments. No `fallbacks`: a silent
     * switch to another model would corrupt the study's model factor.
     */
    public function buildParams(LlmRequest $request): array
    {
        $params = [
            'model' => $this->config->model,
            'maxTokens' => $this->config->maxOutputTokens,
            'system' => $request->system,
            'messages' => [['role' => 'user', 'content' => $request->prompt]],
        ];

        if ($this->config->temperature !== null) {
            $params['temperature'] = $this->config->temperature;
        }

        if ($this->config->reasoningEffort !== null) {
            $params['thinking'] = ['type' => 'adaptive', 'display' => 'summarized'];
            $params['outputConfig'] = ['effort' => $this->config->reasoningEffort];
        }

        return $params;
    }

    public function mapResponse(object $message, int $latencyMs): LlmResponse
    {
        $stopReason = $this->scalar($message->stopReason);

        if ($stopReason === 'refusal') {
            throw new LlmException('Anthropic refused the request.', LlmException::KIND_REFUSAL);
        }

        $text = '';
        $reasoning = '';

        foreach ($message->content as $block) {
            $type = $this->scalar($block->type);

            if ($type === 'text') {
                $text .= $block->text;
            } elseif ($type === 'thinking') {
                $reasoning .= $block->thinking;
            }
        }

        if (trim($text) === '') {
            throw new LlmException("Anthropic returned no text (stop reason: {$stopReason}).", LlmException::KIND_EMPTY);
        }

        return new LlmResponse(
            text: trim($text),
            reasoning: trim($reasoning),
            model: $this->config->model,
            checkpoint: (string) $message->model,
            tokensIn: (int) $message->usage->inputTokens,
            tokensOut: (int) $message->usage->outputTokens,
            // Anthropic bills thinking inside output tokens and reports no separate count.
            tokensReasoning: 0,
            latencyMs: $latencyMs,
            finishReason: $stopReason,
        );
    }

    private function scalar(mixed $value): string
    {
        return $value instanceof BackedEnum ? (string) $value->value : (string) $value;
    }
}
