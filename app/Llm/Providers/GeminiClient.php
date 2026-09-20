<?php

namespace App\Llm\Providers;

use App\Llm\LlmClient;
use App\Llm\LlmException;
use App\Llm\LlmRequest;
use App\Llm\LlmResponse;
use App\Llm\ModelConfig;
use Illuminate\Support\Facades\Http;
use Throwable;

final class GeminiClient implements LlmClient
{
    use CompletesConcurrently;

    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

    public function __construct(
        private readonly ModelConfig $config,
        private readonly string $apiKey,
    ) {}

    public static function make(ModelConfig $config): static
    {
        return new self($config, (string) config('llm.keys.gemini'));
    }

    public function config(): ModelConfig
    {
        return $this->config;
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $start = microtime(true);

        try {
            $body = Http::withHeaders(['x-goog-api-key' => $this->apiKey])
                ->timeout(120)
                ->post(self::BASE_URL."/models/{$this->config->model}:generateContent", $this->buildParams($request))
                ->throw()
                ->json();
        } catch (Throwable $e) {
            throw new LlmException($e->getMessage(), LlmException::KIND_API, $e);
        }

        return $this->mapResponse($body ?? [], (int) round((microtime(true) - $start) * 1000));
    }

    public function buildParams(LlmRequest $request): array
    {
        $generation = [
            'maxOutputTokens' => $this->config->maxOutputTokens,
            'thinkingConfig' => ['includeThoughts' => true],
        ];

        if ($this->config->temperature !== null) {
            $generation['temperature'] = $this->config->temperature;
        }

        return [
            'systemInstruction' => ['parts' => [['text' => $request->system]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $request->prompt]]]],
            'generationConfig' => $generation,
        ];
    }

    public function mapResponse(array $body, int $latencyMs): LlmResponse
    {
        $candidate = $body['candidates'][0] ?? [];
        $finishReason = (string) ($candidate['finishReason'] ?? 'UNKNOWN');

        $text = '';
        $reasoning = '';

        foreach ($candidate['content']['parts'] ?? [] as $part) {
            if (! empty($part['thought'])) {
                $reasoning .= $part['text'] ?? '';
            } else {
                $text .= $part['text'] ?? '';
            }
        }

        if (trim($text) === '') {
            throw new LlmException("Gemini returned no text (finish reason: {$finishReason}).", LlmException::KIND_EMPTY);
        }

        $usage = $body['usageMetadata'] ?? [];

        return new LlmResponse(
            text: trim($text),
            reasoning: trim($reasoning),
            model: $this->config->model,
            checkpoint: (string) ($body['modelVersion'] ?? $this->config->model),
            tokensIn: (int) ($usage['promptTokenCount'] ?? 0),
            tokensOut: (int) ($usage['candidatesTokenCount'] ?? 0),
            tokensReasoning: (int) ($usage['thoughtsTokenCount'] ?? 0),
            latencyMs: $latencyMs,
            finishReason: $finishReason,
        );
    }
}
