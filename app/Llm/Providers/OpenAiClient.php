<?php

namespace App\Llm\Providers;

use App\Llm\LlmClient;
use App\Llm\LlmException;
use App\Llm\LlmRequest;
use App\Llm\LlmResponse;
use App\Llm\ModelConfig;
use OpenAI;
use OpenAI\Contracts\ClientContract;
use OpenAI\Responses\Responses\CreateResponse;
use OpenAI\Responses\Responses\Output\OutputReasoning;
use Throwable;

final class OpenAiClient implements LlmClient
{
    use CompletesConcurrently;

    public function __construct(
        private readonly ModelConfig $config,
        private readonly ClientContract $client,
    ) {}

    public static function make(ModelConfig $config): static
    {
        return new self($config, OpenAI::client((string) config('llm.keys.openai')));
    }

    public function config(): ModelConfig
    {
        return $this->config;
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $start = microtime(true);

        try {
            $response = $this->client->responses()->create($this->buildParams($request));
        } catch (Throwable $e) {
            throw new LlmException($e->getMessage(), LlmException::KIND_API, $e);
        }

        return $this->mapResponse($response, (int) round((microtime(true) - $start) * 1000));
    }

    public function buildParams(LlmRequest $request): array
    {
        $params = [
            'model' => $this->config->model,
            'instructions' => $request->system,
            'input' => $request->prompt,
            'max_output_tokens' => $this->config->maxOutputTokens,
        ];

        if ($this->config->temperature !== null) {
            $params['temperature'] = $this->config->temperature;
        }

        if ($this->config->reasoningEffort !== null) {
            $params['reasoning'] = ['effort' => $this->config->reasoningEffort, 'summary' => 'auto'];
        }

        return $params;
    }

    public function mapResponse(CreateResponse $response, int $latencyMs): LlmResponse
    {
        $text = trim((string) $response->outputText);

        if ($text === '') {
            throw new LlmException("OpenAI returned no text (status: {$response->status}).", LlmException::KIND_EMPTY);
        }

        return new LlmResponse(
            text: $text,
            reasoning: $this->reasoningSummary($response),
            model: $this->config->model,
            checkpoint: $response->model,
            tokensIn: $response->usage?->inputTokens ?? 0,
            tokensOut: $response->usage?->outputTokens ?? 0,
            tokensReasoning: $response->usage?->outputTokensDetails->reasoningTokens ?? 0,
            latencyMs: $latencyMs,
            finishReason: $response->status,
        );
    }

    /** OpenAI never returns raw reasoning, only optional summaries. */
    private function reasoningSummary(CreateResponse $response): string
    {
        $parts = [];

        foreach ($response->output as $item) {
            if ($item instanceof OutputReasoning) {
                foreach ($item->summary as $summary) {
                    $parts[] = $summary->text;
                }
            }
        }

        return trim(implode("\n", $parts));
    }
}
