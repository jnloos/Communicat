<?php

namespace App\Llm;

use App\Models\PromptLog;
use Throwable;

/**
 * Records every call, successful or not, in prompt_logs. Failures are counted,
 * never filtered: the study reports failure rates per model.
 */
final class LoggingLlmClient implements LlmClient
{
    public function __construct(private readonly LlmClient $inner) {}

    public function config(): ModelConfig
    {
        return $this->inner->config();
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        try {
            $response = $this->inner->complete($request);
        } catch (Throwable $e) {
            $this->record($request, null, $e);

            throw $e;
        }

        $this->record($request, $response, null);

        return $response;
    }

    public function completeMany(array $requests): array
    {
        try {
            $responses = $this->inner->completeMany($requests);
        } catch (Throwable $e) {
            foreach ($requests as $request) {
                $this->record($request, null, $e);
            }

            throw $e;
        }

        foreach ($requests as $key => $request) {
            $this->record($request, $responses[$key], null);
        }

        return $responses;
    }

    private function record(LlmRequest $request, ?LlmResponse $response, ?Throwable $error): void
    {
        $config = $this->inner->config();

        PromptLog::create([
            'job_log_id' => $request->jobLogId,
            'label' => $request->expertId === null ? $request->purpose : "{$request->purpose}:{$request->expertId}",
            'purpose' => $request->purpose,
            'expert_id' => $request->expertId,
            'provider' => $config->provider,
            'model' => $config->model,
            'checkpoint' => $response?->checkpoint,
            'config' => $config->toArray(),
            'prompt' => $request->prompt,
            'response' => $response?->text ?? '',
            'reasoning' => $response?->reasoning,
            'tokens_in' => $response?->tokensIn,
            'tokens_out' => $response?->tokensOut,
            'tokens_reasoning' => $response?->tokensReasoning,
            'latency_ms' => $response?->latencyMs,
            'status' => $error === null ? 'ok' : 'failed',
            'error' => $error === null ? null : $this->describe($error),
        ]);
    }

    private function describe(Throwable $error): string
    {
        $kind = $error instanceof LlmException ? $error->kind : LlmException::KIND_API;

        return "{$kind}: {$error->getMessage()}";
    }
}
