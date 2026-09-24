<?php

namespace App\Discussion\Logging;

use App\Discussion\Agents\StudyAgent;
use App\Models\PromptLog;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\StepCompleted;
use Laravel\Ai\Events\StepFailed;
use Throwable;

/**
 * Records every model call, successful or not, in prompt_logs. Failures are
 * never filtered: the study reports failure rates per model.
 *
 * Our agents carry no tools, so one prompt is exactly one step and exactly one
 * row. The prompt text only exists on the PromptingAgent event, so it is held
 * per invocation until the step that consumes it finishes.
 */
class RecordPromptLog
{
    /** @var array<string, string> */
    private array $prompts = [];

    public function whenPrompting(PromptingAgent $event): void
    {
        $this->prompts[$event->invocationId] = $event->prompt->prompt;
    }

    public function whenCompleted(StepCompleted $event): void
    {
        if (! $event->agent instanceof StudyAgent) {
            return;
        }

        $prompt = $this->take($event->invocationId);

        if ($prompt === null) {
            return;
        }

        $rejection = StudyAgent::rejectionReason($event->response->text, $event->response->finishReason);

        $this->write($event->agent, $prompt, [
            'checkpoint' => $event->response->meta->model,
            'response' => $event->response->text,
            'reasoning' => $event->response->reasoning,
            'tokens_in' => $event->response->usage->inputTokens,
            'tokens_out' => $event->response->usage->outputTokens,
            'tokens_reasoning' => $event->response->usage->reasoningTokens,
            'latency_ms' => (int) $event->time,
            'status' => $rejection === null ? 'ok' : 'failed',
            'error' => $rejection,
        ]);
    }

    public function whenStepFailed(StepFailed $event): void
    {
        if (! $event->agent instanceof StudyAgent) {
            return;
        }

        $prompt = $this->take($event->invocationId);

        if ($prompt === null) {
            return;
        }

        $this->write($event->agent, $prompt, [
            'response' => '',
            'latency_ms' => (int) $event->time,
            'status' => 'failed',
            'error' => $this->describe($event->exception),
        ]);
    }

    /** Net for failures that never reached a step; the map still holds the prompt. */
    public function whenAgentFailed(AgentFailed $event): void
    {
        $agent = $event->prompt->agent;

        if (! $agent instanceof StudyAgent || ! isset($this->prompts[$event->invocationId])) {
            return;
        }

        $this->write($agent, $this->take($event->invocationId), [
            'response' => '',
            'status' => 'failed',
            'error' => $this->describe($event->exception),
        ]);
    }

    private function take(string $invocationId): ?string
    {
        $prompt = $this->prompts[$invocationId] ?? null;
        unset($this->prompts[$invocationId]);

        return $prompt;
    }

    private function write(StudyAgent $agent, string $prompt, array $outcome): void
    {
        $purpose = $agent->purpose()->value;

        PromptLog::create([
            'job_log_id' => $agent->jobLogId,
            'purpose' => $purpose,
            'expert_id' => $agent->expertId,
            'label' => $agent->expertId === null ? $purpose : "{$purpose}:{$agent->expertId}",
            'provider' => $agent->model->provider,
            'model' => $agent->model->model,
            'config' => $agent->model->toArray(),
            'prompt' => $prompt,
        ] + $outcome);
    }

    private function describe(Throwable $error): string
    {
        return class_basename($error).': '.$error->getMessage();
    }
}
