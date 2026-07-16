<?php

namespace Tests\Feature\Jobs;

use App\Jobs\Dependencies\ProjectJob;
use App\Jobs\MessageGenerator;
use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use App\Services\Clients\OpenAIClient;
use App\Services\PromptingPipeline\Support\PromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class MessageGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Expert $expert1;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'discussion.reading_chars_per_second' => 10,
            'discussion.reading_delay_min_seconds' => 2,
            'discussion.reading_delay_max_seconds' => 15,
        ]);

        $user = User::factory()->create();
        $this->project = Project::withoutEvents(fn () => Project::create([
            'title' => 'Test Project',
            'description' => 'Test',
            'settings' => [],
            'user_id' => $user->id,
        ]));
        $this->expert1 = Expert::factory()->create(['name' => 'Alice']);
        $this->project->addContributingExpert($this->expert1);
        $this->project->addMessage('Hallo', $user);
    }

    private function mockPipelineDependencies(): void
    {
        $routeJson = json_encode([
            'candidates' => ["E{$this->expert1->id}"],
            'directive' => [
                'role' => 'vertiefen',
                'agenda_step' => 'divergenz',
                'convergence_intent' => 'x',
                'address_user' => false,
            ],
            'reasoning' => 'Test.',
        ]);

        $client = Mockery::mock(OpenAIClient::class);
        $client->shouldReceive('sendFast')->andReturn(
            $routeJson,
            "Alice antwortet kurz.\n---STEUERUNG---\nADRESSAT: none\nPAARTYP: Beitrag→Diskussion"
        );
        $client->shouldReceive('sendSlow')->once()
            ->andReturn("GEDÄCHTNIS-UPDATE:\n[STAND]\nx\nBEITRAGSABSICHT: y.");

        $prompts = Mockery::mock(PromptBuilder::class);
        $prompts->shouldReceive('moderatorRoute')->andReturn('route-prompt');
        $prompts->shouldReceive('think')->andReturn('think-prompt');
        $prompts->shouldReceive('speak')->andReturn('speak-prompt');

        $this->instance(OpenAIClient::class, $client);
        $this->instance(PromptBuilder::class, $prompts);
    }

    public function test_dispatches_next_turn_with_reading_delay(): void
    {
        Queue::fake();
        ProjectJob::startGenerating($this->project->id);
        ProjectJob::markViewing($this->project->id);
        $this->mockPipelineDependencies();

        (new MessageGenerator($this->project->id))->handle();

        Queue::assertPushed(MessageGenerator::class, fn (MessageGenerator $job) => $job->delay !== null);
    }

    public function test_skips_execution_when_generation_flag_cleared(): void
    {
        $before = $this->project->messages()->count();

        (new MessageGenerator($this->project->id))->handle();

        $this->assertSame($before, $this->project->messages()->count());
    }

    public function test_does_not_queue_follow_up_on_user_handoff(): void
    {
        Queue::fake();
        ProjectJob::startGenerating($this->project->id);
        ProjectJob::markViewing($this->project->id);

        $routeJson = json_encode([
            'candidates' => ["E{$this->expert1->id}"],
            'directive' => [
                'role' => 'Nutzer einbeziehen',
                'agenda_step' => 'konvergenz',
                'convergence_intent' => 'Präferenz klären',
                'address_user' => true,
            ],
            'reasoning' => 'Nutzer fragen.',
        ]);

        $client = Mockery::mock(OpenAIClient::class);
        $client->shouldReceive('sendFast')->andReturn(
            $routeJson,
            "Alice fragt dich.\n---STEUERUNG---\nADRESSAT: none\nPAARTYP: Beitrag→Diskussion"
        );
        $client->shouldReceive('sendSlow')->once()
            ->andReturn("GEDÄCHTNIS-UPDATE:\n[STAND]\nx\nBEITRAGSABSICHT: y.");

        $prompts = Mockery::mock(PromptBuilder::class);
        $prompts->shouldReceive('moderatorRoute')->andReturn('route-prompt');
        $prompts->shouldReceive('think')->andReturn('think-prompt');
        $prompts->shouldReceive('speak')->andReturn('speak-prompt');

        $this->instance(OpenAIClient::class, $client);
        $this->instance(PromptBuilder::class, $prompts);

        (new MessageGenerator($this->project->id))->handle();

        Queue::assertNotPushed(MessageGenerator::class);
    }
}
