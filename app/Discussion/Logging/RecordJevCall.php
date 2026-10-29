<?php

namespace App\Discussion\Logging;

use App\Discussion\Values\CallStatus;
use App\Discussion\Values\JevReply;
use App\Discussion\Values\Purpose;
use App\Models\PromptLog;
use Laravel\Ai\Contracts\Question;

/**
 * Writes one prompt_logs row per Jev request, success or failure. Jev is not an
 * agent, so RecordPromptLog (which listens to the agents' events) never sees it;
 * this is the same record for the one call that bypasses it. Failures are never
 * left out: the study reports failure rates per decision maker.
 *
 * It writes the same columns RecordPromptLog writes for a successful agent call
 * (a test keeps the two in step), with the values the API does not report left
 * NULL. Mapping onto the existing columns, no new one: provider the decision
 * model's (`typesafe`), model the pinned id, checkpoint what the response names,
 * prompt the request as JSON (state plus the questions in the SDK's
 * provider-neutral form), response the answers as JSON, config the provider, the
 * model and how many attempts the request took, latency_ms the time from the first
 * attempt to the end of the last (backoff included). Tokens are NULL unless the
 * API reported them: the SDK counts "not reported" as 0, and no real call has zero
 * input tokens. reasoning, tokens_reasoning, tokens_cached and model_served are
 * NULL (not reported by this API). The error is cut to JevReply::ERROR_LIMIT. The
 * ordinary prompt retention (prompts:prune) applies to the prompt text.
 */
class RecordJevCall
{
    /** @param  array<string, Question>  $questions */
    public function record(JevReply $reply, Purpose $purpose, ?int $jobLogId, ?int $expertId, string $state, array $questions): void
    {
        $usage = $reply->response?->usage;
        $provider = $reply->decision->provider;

        PromptLog::create([
            'job_log_id' => $jobLogId,
            'purpose' => $purpose,
            'expert_id' => $expertId,
            'label' => $expertId === null ? "jev:{$purpose->value}" : "jev:{$purpose->value}:{$expertId}",
            'provider' => $provider,
            'model' => $reply->model(),
            'config' => ['provider' => $provider, 'model' => $reply->model(), 'attempts' => $reply->attempts],
            'prompt' => $this->json([
                'model' => $reply->model(),
                'state' => $state,
                'questions' => array_map(fn (Question $question) => $question->toArray(), $questions),
            ]),
            'checkpoint' => $reply->servedModel(),
            'model_served' => null,
            'response' => $reply->succeeded() ? $this->json($reply->response->answers) : '',
            'reasoning' => null,
            'tokens_in' => $usage?->inputTokens ?: null,
            'tokens_out' => $usage?->outputTokens ?: null,
            'tokens_reasoning' => null,
            'tokens_cached' => null,
            'latency_ms' => $reply->latencyMs,
            'status' => $reply->succeeded() ? CallStatus::Ok : CallStatus::Failed,
            'error' => $reply->error,
        ]);
    }

    private function json(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
