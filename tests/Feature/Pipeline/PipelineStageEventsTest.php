<?php

namespace Tests\Feature\Pipeline;

use App\Events\PipelineStageChanged;
use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use App\Services\Clients\OpenAIClient;
use App\Services\PromptingPipeline\DiscussionPipeline;
use App\Services\PromptingPipeline\Support\PromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

class PipelineStageEventsTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Expert $expert1;

    private Expert $expert2;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->project = Project::withoutEvents(fn () => Project::create([
            'title' => 'Test Project',
            'description' => 'Test',
            'settings' => [],
            'user_id' => $user->id,
        ]));
        $this->expert1 = Expert::factory()->create(['name' => 'Alice']);
        $this->expert2 = Expert::factory()->create(['name' => 'Bob']);
        $this->project->addContributingExpert($this->expert1);
        $this->project->addContributingExpert($this->expert2);
        $this->project->addMessage('Hallo, wie seht ihr das?', $user);
    }

    public function test_turn_broadcasts_routing_thinking_and_speaking_stages(): void
    {
        Event::fake([PipelineStageChanged::class]);

        $routeJson = json_encode([
            'candidates' => ["E{$this->expert1->id}", "E{$this->expert2->id}"],
            'directive' => ['role' => 'vertiefen', 'agenda_step' => 'divergenz', 'convergence_intent' => 'x', 'address_user' => false],
            'reasoning' => 'Test.',
        ]);
        $thinkResponse = "GEDÄCHTNIS-UPDATE:\n[STAND]\nx\nBEITRAGSABSICHT: y.";

        $client = Mockery::mock(OpenAIClient::class);
        $client->shouldReceive('sendFast')->andReturn(
            $routeJson,
            json_encode(['winner' => "E{$this->expert2->id}", 'reasoning' => 'r']),
            "Bob sagt etwas.\n---STEUERUNG---\nADRESSAT: none\nPAARTYP: Beitrag→Diskussion"
        );
        $client->shouldReceive('sendManySlow')->once()->andReturn([
            $this->expert1->id => $thinkResponse,
            $this->expert2->id => $thinkResponse,
        ]);

        $prompts = Mockery::mock(PromptBuilder::class);
        $prompts->shouldReceive('moderatorRoute')->andReturn('route-prompt');
        $prompts->shouldReceive('think')->andReturn('think-prompt');
        $prompts->shouldReceive('moderatorSelect')->andReturn('select-prompt');
        $prompts->shouldReceive('speak')->andReturn('speak-prompt');

        $this->instance(OpenAIClient::class, $client);
        $this->instance(PromptBuilder::class, $prompts);

        (new DiscussionPipeline($this->project))->run();

        Event::assertDispatched(PipelineStageChanged::class, fn ($e) => $e->stage === 'routing' && $e->experts === []
        );
        Event::assertDispatched(PipelineStageChanged::class, fn ($e) => $e->stage === 'thinking'
            && collect($e->experts)->pluck('id')->sort()->values()->all()
                === collect([$this->expert1->id, $this->expert2->id])->sort()->values()->all()
        );
        Event::assertDispatched(PipelineStageChanged::class, fn ($e) => $e->stage === 'speaking'
            && count($e->experts) === 1
            && $e->experts[0]['id'] === $this->expert2->id
            && $e->experts[0]['name'] === 'Bob'
        );
    }
}
