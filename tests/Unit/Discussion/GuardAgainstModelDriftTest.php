<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Agents\SpeakAgent;
use App\Discussion\Agents\ThinkAgent;
use App\Discussion\Logging\GuardAgainstModelDrift;
use App\Discussion\ModelDrifted;
use App\Discussion\Values\JobStatus;
use App\Discussion\Values\ModelConfig;
use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Project;
use App\Models\PromptLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Events\StepCompleted;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\Fakes\FakeAgents;
use Tests\TestCase;

class GuardAgainstModelDriftTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    public function test_another_model_than_asked_for_throws_with_both_names(): void
    {
        $agent = new SpeakAgent($this->model('omniroute', 'openai/gpt-5-mini'), expertId: 12);

        $this->expectException(ModelDrifted::class);
        $this->expectExceptionMessage('openai/gpt-5-mini');
        $this->expectExceptionMessage('gpt-5');

        $this->handle($agent, 'gpt-5');
    }

    /** The real SDK loop fires StepCompleted outside its own catch, so the guard's exception reaches TurnRunner. */
    public function test_drift_fails_the_turn_and_aborts_project_run(): void
    {
        $project = Project::factory()->create(['pipeline' => 'RoundRobinPipeline', 'model' => 'openai-gpt-5-mini']);
        $project->addContributingExpert(Expert::factory()->create());
        FakeAgents::always(ThinkAgent::class, ['thought' => 'Gedanke.']);
        SpeakAgent::fake(fn () => new StructuredTextResponse(['contribution' => 'Text.', 'addressee' => null], '{}', new TextUsage, new Meta('openai', 'gpt-5')));

        $this->artisan('project:run', ['project' => $project->id, '--turns' => 3])
            ->expectsOutputToContain('Aborted: ModelDrifted')
            ->assertFailed();

        $job = JobLog::sole();
        $this->assertSame(JobStatus::Failed, $job->status);
        $this->assertStringStartsWith('ModelDrifted:', $job->error);
        $this->assertSame('gpt-5', PromptLog::where('purpose', 'speak')->sole()->model_served, 'the call itself is on record');
    }

    public function test_snapshots_and_gateway_prefixes_are_no_drift(): void
    {
        $this->expectNotToPerformAssertions();

        $this->handle(new SpeakAgent($this->model('openai', 'gpt-5-mini')), 'gpt-5-mini-2025-08-07');
        $this->handle(new SpeakAgent($this->model('omniroute', 'openai/gpt-5-mini')), 'gpt-5-mini-2025-08-07');
        $this->handle(new SpeakAgent($this->model('omniroute', 'openai/gpt-5-mini')), 'gpt-5-mini');
    }

    public function test_a_response_that_names_no_model_is_no_drift(): void
    {
        $this->expectNotToPerformAssertions();

        $this->handle(new SpeakAgent($this->model('omniroute', 'openai/gpt-5-mini')), '');
        $this->handle(new SpeakAgent($this->model('omniroute', 'openai/gpt-5-mini')), null);
    }

    /** Anthropic and Gemini only echo the requested id, so there is nothing to compare. */
    public function test_providers_that_do_not_report_a_served_model_are_skipped(): void
    {
        $this->expectNotToPerformAssertions();

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
