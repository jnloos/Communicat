<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Agents\SpeakAgent;
use App\Discussion\Logging\GuardAgainstModelDrift;
use App\Discussion\Values\ModelConfig;
use App\Models\JobLog;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Events\StepCompleted;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class GuardAgainstModelDriftTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    public function test_another_model_than_asked_for_is_logged_with_project_turn_and_expert(): void
    {
        $project = Project::factory()->create();
        $job = JobLog::create(['job_class' => 'x', 'project_id' => $project->id, 'status' => 'running', 'started_at' => now(), 'turn_index' => 7]);
        $agent = new SpeakAgent($this->model('omniroute', 'openai/gpt-5-mini'), jobLogId: $job->id, expertId: 12);

        Log::shouldReceive('error')->once()->withArgs(fn (string $message, array $context) => $context === [
            'project_id' => $project->id,
            'turn_index' => 7,
            'expert_id' => 12,
            'requested' => 'openai/gpt-5-mini',
            'served' => 'gpt-5',
        ]);

        $this->handle($agent, 'gpt-5');
    }

    public function test_snapshots_and_gateway_prefixes_are_no_drift(): void
    {
        Log::shouldReceive('error')->never();

        $this->handle(new SpeakAgent($this->model('openai', 'gpt-5-mini')), 'gpt-5-mini-2025-08-07');
        $this->handle(new SpeakAgent($this->model('omniroute', 'openai/gpt-5-mini')), 'gpt-5-mini-2025-08-07');
        $this->handle(new SpeakAgent($this->model('omniroute', 'openai/gpt-5-mini')), 'gpt-5-mini');
    }

    public function test_a_response_that_names_no_model_is_no_drift(): void
    {
        Log::shouldReceive('error')->never();

        $this->handle(new SpeakAgent($this->model('omniroute', 'openai/gpt-5-mini')), '');
        $this->handle(new SpeakAgent($this->model('omniroute', 'openai/gpt-5-mini')), null);
    }

    /** Anthropic and Gemini only echo the requested id, so there is nothing to compare. */
    public function test_providers_that_do_not_report_a_served_model_are_skipped(): void
    {
        Log::shouldReceive('error')->never();

        $this->handle(new SpeakAgent($this->model('anthropic', 'claude-opus-5')), 'something-else');
    }

    public function test_the_guard_listens_to_completed_steps(): void
    {
        $this->assertTrue(Event::hasListeners(StepCompleted::class));
    }

    private function handle(SpeakAgent $agent, ?string $served): void
    {
        $response = new StepResponse('t', [], FinishReason::Stop, new TextUsage(1, 1), new Meta('x', $served));

        app(GuardAgainstModelDrift::class)->handle(
            new StepCompleted('inv', 1, $agent, Mockery::mock(TextProvider::class), $agent->model->model, true, $response, 1.0)
        );
    }

    private function model(string $provider, string $model): ModelConfig
    {
        return new ModelConfig('probe', 'Probe', $provider, $model, 8000, null, null);
    }
}
