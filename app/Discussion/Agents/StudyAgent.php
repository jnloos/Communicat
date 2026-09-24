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
use Laravel\Ai\Promptable;

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
     *   CAVEAT: the gateway performs no camelCase→snake_case renaming, so the
     *   `outputConfig` key — the named argument the anthropic-php SDK used to map to
     *   the wire name `output_config` (MessageCreateParams.php:173) — now goes onto
     *   the wire verbatim. Kept verbatim here on purpose; whether it must become
     *   `output_config` is a spec question, not one for this class.
     * - Gemini/Concerns/BuildsTextRequests.php:105-128 folds it into
     *   `generation_config` with the key spelling preserved (`thinkingConfig` is not
     *   in TOP_LEVEL_INTERACTION_KEYS at :21-25), which is where the old adapter put
     *   `thinkingConfig.includeThoughts` too.
     */
    public function providerOptions(Lab|string $provider): array
    {
        $effort = $this->model->reasoningEffort;
        $lab = $provider instanceof Lab ? $provider : Lab::tryFrom($provider);

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

        return match ($lab) {
            Lab::OpenAI => ['reasoning' => ['effort' => $effort, 'summary' => 'auto']],
            Lab::Anthropic => [
                'thinking' => ['type' => 'adaptive', 'display' => 'summarized'],
                'outputConfig' => ['effort' => $effort],
            ],
            default => [],
        };
    }

    /** One call, one Completion. Never returns an AgentResponse: it is not serializable. */
    public function ask(string $prompt): Completion
    {
        $response = $this->prompt($prompt, provider: $this->model->lab(), model: $this->model->model);

        return new Completion(
            text: $response->text,
            reasoning: $response->reasoning,
            inputTokens: $response->usage->inputTokens,
            outputTokens: $response->usage->outputTokens,
            reasoningTokens: $response->usage->reasoningTokens,
        );
    }
}
