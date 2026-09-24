<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Agents\SpeakAgent;
use App\Discussion\Agents\SummarizeAgent;
use App\Discussion\Agents\ThinkAgent;
use App\Discussion\Purpose;
use App\Discussion\Support\PromptRenderer;
use App\Discussion\Values\ModelConfig;
use Laravel\Ai\Enums\Lab;
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
        $this->expectException(\InvalidArgumentException::class);

        (new SpeakAgent($this->model('gemini', effort: 'low')))->providerOptions(Lab::Gemini);
    }

    public function test_a_null_effort_sends_no_reasoning_options_at_all(): void
    {
        $this->assertSame([], (new SpeakAgent($this->model('openai', effort: null)))->providerOptions(Lab::OpenAI));
    }

    public function test_an_effort_on_a_lab_without_a_mapping_is_rejected_instead_of_silently_dropped(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SpeakAgent($this->model('groq', effort: 'low')))->providerOptions(Lab::Groq);
    }

    public function test_a_lab_without_a_mapping_and_no_effort_needs_no_options(): void
    {
        $this->assertSame([], (new SpeakAgent($this->model('groq', effort: null)))->providerOptions(Lab::Groq));
    }
}
