<?php

namespace App\Discussion\Agents;

use App\Discussion\Schemas\ResponseSchema;
use App\Discussion\Support\PromptRenderer;
use App\Discussion\Values\Completion;
use App\Discussion\Values\ModelConfig;
use App\Discussion\Values\Purpose;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\AgentInput;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\StructuredAgentResponse;

/**
 * What every model call in this study shares. The metadata travels with the
 * agent because the SDK hands the agent instance to its Step events, which is
 * where prompt_logs gets written.
 *
 * The renderer is resolved from the container instead of injected: agents are
 * built with `new` inside closures that cross a process boundary when prompts
 * run in parallel, so the object must stay free of bound services.
 */
abstract class StudyAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable {
        // The trait's own prompt() stays reachable under this name; the override
        // below is what callers get. A class method beats a trait method, so the
        // alias is the only way back to the original.
        prompt as private promptThroughSdk;
    }

    /**
     * @param  class-string<ResponseSchema>|null  $schema  the shape the answer must have
     *
     * The schema arrives as a class name, not an object: agents are rebuilt
     * inside a child process when ParallelPrompts runs a round, and only
     * scalars survive that boundary.
     */
    public function __construct(
        public readonly ModelConfig $model,
        public readonly ?int $jobLogId = null,
        public readonly ?int $expertId = null,
        public readonly ?string $schema = null,
    ) {}

    /**
     * The SDK asks every agent for its schema and requests structured output
     * only when the array is non-empty, so an agent without one behaves exactly
     * as before.
     */
    public function schema(JsonSchema $json): array
    {
        return $this->schema === null ? [] : (new $this->schema)->fields($json);
    }

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
     * The reasoning parameters each provider expects.
     *
     * Every branch below was verified against the live API on 26 September
     * 2026, not just against the SDK source -- the previous shapes were carried
     * over from the old adapters and two of the three were wrong:
     *
     * - **Anthropic** takes `thinking` as `{type: adaptive, display: summarized}`
     *   and reports reasoning tokens for it. It does NOT take `output_config`:
     *   sending `output_config.effort` makes it answer with
     *   `stop_reason: refusal` and a contribution cut off mid-sentence, which
     *   this codebase then correctly threw away as a refusal. `{type: enabled,
     *   budget_tokens: N}` is rejected with a 400. There is therefore no way to
     *   set a reasoning effort here, so a model key that configures one throws
     *   rather than having it silently dropped -- run_config must never claim a
     *   level the provider never received.
     * - **Gemini** takes no reasoning options at all. `thinkingConfig` is
     *   rejected with "Unknown parameter", in camelCase and snake_case alike.
     *   Thinking is on by default and the usage block reports its tokens, which
     *   is the only part of it the study records.
     * - **OpenAI** keeps `reasoning.effort`; untested against the live API
     *   because the account has no credit.
     */
    public function providerOptions(Lab|string $provider): array
    {
        $effort = $this->model->reasoningEffort;
        $lab = $provider instanceof Lab ? $provider : Lab::tryFrom($provider);

        if ($lab === null) {
            throw new InvalidArgumentException(
                "Provider [{$provider}] is unknown to the AI SDK; model key [{$this->model->key}]."
            );
        }

        // Gemini and Anthropic have no effort knob we can set. Swallowing a
        // configured one would leave run_config describing a run that never
        // happened, so it is an error rather than a default.
        if (($lab === Lab::Gemini || $lab === Lab::Anthropic) && $effort !== null) {
            throw new InvalidArgumentException(sprintf(
                '%s has no reasoning-effort parameter this SDK can set; model key [%s] configures one.',
                $lab->value,
                $this->model->key,
            ));
        }

        return match ($lab) {
            Lab::Gemini => [],
            Lab::Anthropic => ['thinking' => ['type' => 'adaptive', 'display' => 'summarized']],
            Lab::OpenAI => $effort === null ? [] : ['reasoning' => ['effort' => $effort, 'summary' => 'auto']],
            default => $effort === null ? [] : throw new InvalidArgumentException(
                "No reasoning-effort mapping for lab [{$lab->value}]; model key [{$this->model->key}] sets one."
            ),
        };
    }

    /**
     * Promptable::prompt() is public, and a caller reaching for it directly gets
     * config('ai.default') — the SDK's default *provider* — instead of this
     * project's model. That is a run with mixed model families which leaves no
     * trace: not in job_logs, not in prompt_logs.provider, and not in the failover
     * guard. ask() is the way in, and this override makes every other route fail
     * loudly rather than quietly corrupt a cell of the study.
     *
     * Signature copied verbatim from the trait; any drift here would be a fatal
     * incompatibility rather than a guard.
     */
    public function prompt(
        AgentInput|UserMessage|Decisions|string $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null): AgentResponse
    {
        if ($provider !== $this->model->lab() || $model !== $this->model->model) {
            throw new InvalidArgumentException(sprintf(
                'Prompt %s through ask(): prompt() was called with provider [%s] and model [%s], but model key [%s] expects [%s] and [%s].',
                static::class,
                is_object($provider) ? $provider->value : var_export($provider, true),
                var_export($model, true),
                $this->model->key,
                $this->model->lab()->value,
                $this->model->model,
            ));
        }

        return $this->promptThroughSdk($prompt, $attachments, $provider, $model, $timeout);
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
            structured: $response instanceof StructuredAgentResponse ? $response->structured : [],
        );
    }

    /**
     * The rejection rule the provider adapters carried (AnthropicClient.php:73-75
     * and 90-92, with the same checks in OpenAiClient and GeminiClient): an empty
     * answer after trim, or a finish reason of ContentFilter (a provider refusal),
     * disqualify a response. Static and shared with RecordPromptLog, which applies
     * the same rule to StepCompleted so a refused or empty answer lands in
     * prompt_logs as status 'failed' instead of 'ok' — ensureAnswered() below only
     * ever sees that outcome after RecordPromptLog's listener has already run for
     * the same event, so the two must agree on what counts as a rejection.
     *
     * Returns the reason to record, or null when the response is usable.
     */
    public static function rejectionReason(string $text, ?FinishReason $finishReason): ?string
    {
        if ($finishReason === FinishReason::ContentFilter) {
            return 'The model refused to answer.';
        }

        if (trim($text) === '') {
            return 'The model returned no text.';
        }

        return null;
    }

    /**
     * The guard the provider adapters carried: an empty or refused answer never
     * reaches a stage.
     *
     * With a schema the response text is the encoded JSON and therefore never
     * empty, so this no longer protects a stage from an empty *field*. Each
     * stage checks its own required fields for that reason -- Summarize most of
     * all, because an empty summary would overwrite the study's long-term memory
     * while the turn still counted as a success.
     *
     * A refusal is only reachable through the last step: AgentResponse carries no
     * finish reason of its own, and Anthropic's `refusal` stop reason arrives as
     * FinishReason::ContentFilter (Anthropic/Concerns/ParsesTextResponses.php:226).
     */
    private function ensureAnswered(AgentResponse $response): void
    {
        $reason = self::rejectionReason($response->text, $response->steps->last()?->finishReason);

        if ($reason === null) {
            return;
        }

        $purpose = $this->purpose()->value;

        throw new AiException("{$reason} ({$purpose} call of model key [{$this->model->key}]).");
    }
}
