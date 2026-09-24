<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Agents\SpeakAgent;
use App\Discussion\Agents\StudyAgent;
use App\Discussion\Logging\RecordPromptLog;
use App\Discussion\Values\ModelConfig;
use App\Models\Expert;
use App\Models\JobLog;
use App\Models\PromptLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\StepCompleted;
use Laravel\Ai\Events\StepFailed;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Mockery;
use Tests\TestCase;

class RecordPromptLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_successful_step_becomes_one_row(): void
    {
        $log = JobLog::create(['job_class' => 'test', 'status' => 'running', 'started_at' => now()]);
        $expert = Expert::factory()->create();
        $agent = new SpeakAgent($this->model(), jobLogId: $log->id, expertId: $expert->id);

        $recorder = app(RecordPromptLog::class);
        $recorder->whenPrompting($this->promptingEvent('inv-1', $agent, 'Der gerenderte Prompt'));
        $recorder->whenCompleted($this->completedEvent('inv-1', $agent, text: 'Antwort', reasoning: 'Gedanke'));

        $row = PromptLog::sole();

        $this->assertSame($log->id, $row->job_log_id);
        $this->assertSame('speak', $row->purpose);
        $this->assertSame($expert->id, $row->expert_id);
        $this->assertSame("speak:{$expert->id}", $row->label);
        $this->assertSame('Der gerenderte Prompt', $row->prompt);
        $this->assertSame('Antwort', $row->response);
        $this->assertSame('Gedanke', $row->reasoning);
        $this->assertSame('ok', $row->status);
        $this->assertNull($row->error);
        $this->assertSame('probe-model', $row->model);
        $this->assertSame('probe-model-001', $row->checkpoint);
        $this->assertSame(11, $row->tokens_in);
        $this->assertSame(22, $row->tokens_out);
        $this->assertSame(3, $row->tokens_reasoning);
        $this->assertSame(42, $row->latency_ms);
        $this->assertSame('probe', $row->config['key']);
    }

    public function test_a_failed_step_is_recorded_not_dropped(): void
    {
        $agent = new SpeakAgent($this->model());

        $recorder = app(RecordPromptLog::class);
        $recorder->whenPrompting($this->promptingEvent('inv-2', $agent, 'Prompt'));
        $recorder->whenStepFailed($this->failedEvent('inv-2', $agent, new \RuntimeException('overloaded')));

        $row = PromptLog::sole();

        $this->assertSame('failed', $row->status);
        $this->assertStringContainsString('overloaded', $row->error);
        $this->assertSame('', $row->response);
        $this->assertNull($row->tokens_in);
        $this->assertSame('speak', $row->label);
    }

    public function test_the_prompt_map_does_not_leak_between_invocations(): void
    {
        $agent = new SpeakAgent($this->model());
        $recorder = app(RecordPromptLog::class);

        $recorder->whenPrompting($this->promptingEvent('inv-3', $agent, 'Erster Prompt'));
        $recorder->whenCompleted($this->completedEvent('inv-3', $agent));
        $recorder->whenCompleted($this->completedEvent('inv-3', $agent));

        $this->assertSame(1, PromptLog::count());
    }

    private function model(): ModelConfig
    {
        return new ModelConfig('probe', 'Probe', 'openai', 'probe-model', 8000, null, 'low');
    }

    private function provider(): TextProvider
    {
        return Mockery::mock(TextProvider::class);
    }

    private function promptingEvent(string $id, StudyAgent $agent, string $prompt): PromptingAgent
    {
        return new PromptingAgent($id, new AgentPrompt(
            agent: $agent,
            prompt: $prompt,
            attachments: [],
            provider: $this->provider(),
            model: 'probe-model',
        ));
    }

    private function completedEvent(
        string $id,
        StudyAgent $agent,
        string $text = 'Antwort',
        string $reasoning = '',
    ): StepCompleted {
        $response = new StepResponse(
            text: $text,
            toolCalls: [],
            finishReason: FinishReason::Stop,
            usage: new TextUsage(11, 22, null, null, 3),
            meta: new Meta('openai', 'probe-model-001'),
            reasoning: $reasoning,
        );

        return new StepCompleted($id, 1, $agent, $this->provider(), 'probe-model', true, $response, 42.0);
    }

    private function failedEvent(string $id, StudyAgent $agent, \Throwable $e): StepFailed
    {
        return new StepFailed($id, 1, $agent, $this->provider(), 'probe-model', true, $e, 42.0);
    }
}
