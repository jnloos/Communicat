<?php

namespace App\Discussion\Agents;

use App\Discussion\Purpose;
use App\Discussion\Support\PromptRenderer;
use App\Discussion\Values\Completion;
use App\Discussion\Values\ModelConfig;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;

/**
 * What every model call in this study shares. The metadata travels with the
 * agent because the SDK hands the agent instance to its Step events, which is
 * where prompt_logs gets written.
 *
 * The renderer is resolved from the container instead of injected: agents are
 * built with `new` inside closures that cross a process boundary when prompts
 * run in parallel, so the object must stay free of bound services.
 */
abstract class StudyAgent implements Agent, HasProviderOptions
{
    use Promptable;

    public function __construct(
        public readonly ModelConfig $model,
        public readonly ?int $jobLogId = null,
        public readonly ?int $expertId = null,
    ) {}

    abstract public function purpose(): Purpose;

    public function instructions(): string
    {
        return app(PromptRenderer::class)->system();
    }

    public function maxTokens(): ?int
    {
        return $this->model->maxOutputTokens;
    }

    public function temperature(): ?float
    {
        return $this->model->temperature;
    }

    /**
     * The reasoning parameters each provider expects. These are carried over
     * verbatim from the adapters they replace: a different shape means the model
     * answers differently, and runs stop being comparable.
     *
     * Verified against the SDK gateways (laravel/ai v1.0.0): this array reaches the
     * request body unfiltered and last, so our keys win and none is dropped.
     * - OpenAi/Concerns/BuildsTextRequests.php:110-113 merges it onto the body with
     *   array_merge; the gateway never sets `reasoning` itself, so `reasoning.effort`
     *   and `reasoning.summary` arrive as written. (It additionally appends
     *   `include: ['reasoning.encrypted_content']` at :120-125 for reasoning models —
     *   a response-shape addition, not a change to our parameters.)
     * - Anthropic/Concerns/BuildsTextRequests.php:73 merges it onto the body last, so
     *   `thinking.type`/`thinking.display` arrive as written; the gateway sets
     *   `output_config` only for structured output (:44), which we never request.
     *   The key is spelled `output_config`, not `outputConfig`: the gateway hands
     *   provider options to the API untouched and maps no camelCase, while the
     *   Anthropic API is snake_case throughout (`max_tokens`, `stop_sequences`,
     *   `budget_tokens`). The old adapter reached the same wire name via
     *   anthropic-ai/sdk (MessageCreateParams.php:173), which mapped its camelCase
     *   named argument. "Carried over verbatim" means what arrives at the provider,
     *   not how the old adapter spelled it in PHP.
     * - Gemini/Concerns/BuildsTextRequests.php:105-128 folds it into
     *   `generation_config` with the key spelling preserved (`thinkingConfig` is not
     *   in TOP_LEVEL_INTERACTION_KEYS at :21-25), which is where the old adapter put
     *   `thinkingConfig.includeThoughts` too.
     */
    public function providerOptions(Lab|string $provider): array
    {
        $effort = $this->model->reasoningEffort;
        $lab = $provider instanceof Lab ? $provider : Lab::tryFrom($provider);

        // An unresolvable provider string would fall past the Gemini branch and drop
        // thinkingConfig.includeThoughts, so the reasoning texts would stop being
        // recorded. Unreachable with the configured providers, cheap to close.
        if ($lab === null) {
            throw new InvalidArgumentException(
                "Provider [{$provider}] is unknown to the AI SDK; model key [{$this->model->key}]."
            );
        }

        if ($lab === Lab::Gemini) {
            if ($effort !== null) {
                throw new InvalidArgumentException(
                    "Gemini has no reasoning-effort levels; model key [{$this->model->key}] sets one."
                );
            }

            return ['thinkingConfig' => ['includeThoughts' => true]];
        }

        if ($effort === null) {
            return [];
        }

        // A lab without a mapping must not swallow a configured effort: run_config
        // would claim a reasoning level the provider never received. Same fail-fast
        // reasoning as the Gemini branch above.
        return match ($lab) {
            Lab::OpenAI => ['reasoning' => ['effort' => $effort, 'summary' => 'auto']],
            Lab::Anthropic => [
                'thinking' => ['type' => 'adaptive', 'display' => 'summarized'],
                'output_config' => ['effort' => $effort],
            ],
            default => throw new InvalidArgumentException(
                "No reasoning-effort mapping for lab [{$lab->value}]; model key [{$this->model->key}] sets one."
            ),
        };
    }

    /** One call, one Completion. Never returns an AgentResponse: it is not serializable. */
    public function ask(string $prompt): Completion
    {
        $response = $this->prompt($prompt, provider: $this->model->lab(), model: $this->model->model);

        $this->ensureAnswered($response);

        return new Completion(
            text: $response->text,
            reasoning: $response->reasoning,
            inputTokens: $response->usage->inputTokens,
            outputTokens: $response->usage->outputTokens,
            reasoningTokens: $response->usage->reasoningTokens,
        );
    }

    /**
     * The guard the provider adapters carried (AnthropicClient.php:73-75 and 90-92,
     * with the same checks in OpenAiClient and GeminiClient). It belongs here rather
     * than in a stage: Speak catches an empty visible text and ThinkAsSpeaker a
     * missing GEDANKE: marker on their own, but Summarize would assign the empty
     * string straight to projects.long_term_memory and silently erase the study's
     * long-term memory, where the turn used to be logged as failed.
     *
     * A refusal is only reachable through the last step: AgentResponse carries no
     * finish reason of its own, and Anthropic's `refusal` stop reason arrives as
     * FinishReason::ContentFilter (Anthropic/Concerns/ParsesTextResponses.php:226).
     */
    private function ensureAnswered(AgentResponse $response): void
    {
        $purpose = $this->purpose()->value;

        if ($response->steps->last()?->finishReason === FinishReason::ContentFilter) {
            throw new AiException("The model refused the {$purpose} call of model key [{$this->model->key}].");
        }

        if (trim($response->text) === '') {
            throw new AiException("The model returned no text for the {$purpose} call of model key [{$this->model->key}].");
        }
    }
}
