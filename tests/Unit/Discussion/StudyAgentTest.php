<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Agents\JudgeAgent;
use App\Discussion\Agents\SelectorAgent;
use App\Discussion\Agents\SpeakAgent;
use App\Discussion\Agents\SummarizeAgent;
use App\Discussion\Agents\ThinkAgent;
use App\Discussion\Support\PromptRenderer;
use App\Discussion\Values\ModelConfig;
use App\Discussion\Values\Purpose;
use InvalidArgumentException;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\AgentInput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\TextUsage;
use Tests\TestCase;

class StudyAgentTest extends TestCase
{
    private function model(string $provider, ?string $effort = 'low', ?float $temperature = null): ModelConfig
    {
        return new ModelConfig(
            key: 'probe', label: 'Probe', provider: $provider, model: 'probe-model',
            maxOutputTokens: 8000, temperature: $temperature, reasoningEffort: $effort,
        );
    }

    public function test_each_agent_carries_its_purpose(): void
    {
        $this->assertSame(Purpose::Think, (new ThinkAgent($this->model('openai')))->purpose());
        $this->assertSame(Purpose::Speak, (new SpeakAgent($this->model('openai')))->purpose());
        $this->assertSame(Purpose::Summarize, (new SummarizeAgent($this->model('openai')))->purpose());
    }

    public function test_instructions_are_the_shared_system_prompt(): void
    {
        $agent = new SpeakAgent($this->model('openai'));

        $this->assertSame(app(PromptRenderer::class)->system(), $agent->instructions());
    }

    public function test_model_options_come_from_the_model_config(): void
    {
        $agent = new SpeakAgent($this->model('openai', temperature: 0.4));

        $this->assertSame(8000, $agent->maxTokens());
        $this->assertSame(0.4, $agent->temperature());
    }

    public function test_openai_gets_effort_and_a_summary_request(): void
    {
        $options = (new SpeakAgent($this->model('openai')))->providerOptions(Lab::OpenAI);

        $this->assertSame(['reasoning' => ['effort' => 'low', 'summary' => 'auto']], $options);
    }

    public function test_anthropic_gets_adaptive_thinking_and_an_output_effort(): void
    {
        $options = (new SpeakAgent($this->model('anthropic')))->providerOptions(Lab::Anthropic);

        $this->assertSame([
            'thinking' => ['type' => 'adaptive', 'display' => 'summarized'],
            'output_config' => ['effort' => 'low'],
        ], $options);
    }

    public function test_gemini_asks_for_thoughts_but_never_for_an_effort_level(): void
    {
        $options = (new SpeakAgent($this->model('gemini', effort: null)))->providerOptions(Lab::Gemini);

        $this->assertSame(['thinkingConfig' => ['includeThoughts' => true]], $options);
    }

    public function test_an_effort_on_gemini_is_rejected_instead_of_silently_dropped(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new SpeakAgent($this->model('gemini', effort: 'low')))->providerOptions(Lab::Gemini);
    }

    public function test_a_null_effort_sends_no_reasoning_options_at_all(): void
    {
        $this->assertSame([], (new SpeakAgent($this->model('openai', effort: null)))->providerOptions(Lab::OpenAI));
    }

    public function test_an_effort_on_a_lab_without_a_mapping_is_rejected_instead_of_silently_dropped(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new SpeakAgent($this->model('groq', effort: 'low')))->providerOptions(Lab::Groq);
    }

    public function test_a_lab_without_a_mapping_and_no_effort_needs_no_options(): void
    {
        $this->assertSame([], (new SpeakAgent($this->model('groq', effort: null)))->providerOptions(Lab::Groq));
    }

    public function test_a_provider_the_sdk_cannot_resolve_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new SpeakAgent($this->model('gemini', effort: null)))->providerOptions('not-a-lab');
    }

    public function test_ask_maps_the_response_onto_a_completion(): void
    {
        $response = $this->response('Der Vorschlag trägt.', reasoning: 'Abgewogen.');

        $completion = $this->agentReturning($response)->ask('Sag was.');

        $this->assertSame('Der Vorschlag trägt.', $completion->text);
        $this->assertSame('Abgewogen.', $completion->reasoning);
        $this->assertSame(11, $completion->inputTokens);
        $this->assertSame(22, $completion->outputTokens);
        $this->assertSame(7, $completion->reasoningTokens);
    }

    public function test_an_empty_answer_is_an_error_rather_than_an_empty_completion(): void
    {
        $this->expectException(AiException::class);

        $this->agentReturning($this->response("  \n "))->ask('Sag was.');
    }

    public function test_a_refused_answer_is_an_error(): void
    {
        $this->expectException(AiException::class);

        $this->agentReturning(
            $this->response('Teilweise geantwortet.', finishReason: FinishReason::ContentFilter)
        )->ask('Sag was.');
    }

    public function test_the_selector_and_judge_agents_carry_their_own_purpose(): void
    {
        $this->assertSame(Purpose::Select, (new SelectorAgent($this->model('openai')))->purpose());
        $this->assertSame(Purpose::Judge, (new JudgeAgent($this->model('openai')))->purpose());
    }

    public function test_the_judge_purpose_is_stored_as_judge(): void
    {
        $this->assertSame('judge', Purpose::Judge->value);
    }

    public function test_prompting_with_a_foreign_provider_is_refused(): void
    {
        $agent = new SelectorAgent($this->model('openai'));

        $this->expectException(InvalidArgumentException::class);

        // Promptable::prompt() is public. Called directly with someone else's lab
        // it would silently use that provider instead of the project's model.
        $agent->prompt('Wer spricht?', provider: Lab::Anthropic, model: 'claude-opus-5');
    }

    public function test_prompting_without_a_provider_is_refused(): void
    {
        $agent = new SelectorAgent($this->model('openai'));

        $this->expectException(InvalidArgumentException::class);

        // No provider means config('ai.default') — the study's model choice bypassed.
        $agent->prompt('Wer spricht?');
    }

    private function response(
        string $text,
        string $reasoning = '',
        FinishReason $finishReason = FinishReason::Stop,
    ): AgentResponse {
        $usage = new TextUsage(inputTokens: 11, outputTokens: 22, reasoningTokens: 7);
        $meta = new Meta('openai', 'probe-model');

        $response = new AgentResponse('probe-invocation', $text, $usage, $meta);
        $response->reasoning = $reasoning;

        return $response->withSteps(collect([
            new Step($text, [], [], $finishReason, $usage, $meta, $reasoning, []),
        ]));
    }

    /** Doubles the one seam ask() depends on, so the mapping and the guards are testable without a gateway. */
    private function agentReturning(AgentResponse $response): SpeakAgent
    {
        return new class($this->model('openai'), $response) extends SpeakAgent
        {
            public function __construct(ModelConfig $model, private readonly AgentResponse $canned)
            {
                parent::__construct($model);
            }

            public function prompt(
                AgentInput|UserMessage|Decisions|string $prompt,
                array $attachments = [],
                Lab|array|string|null $provider = null,
                ?string $model = null,
                ?int $timeout = null,
            ): AgentResponse {
                return $this->canned;
            }
        };
    }
}
