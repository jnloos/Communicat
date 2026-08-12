<?php

namespace Tests\Feature\Jobs;

use App\Events\MessageGenerated;
use App\Jobs\Dependencies\ProjectJob;
use App\Jobs\MessageGenerator;
use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use App\Services\Clients\OpenAIClient;
use App\Services\PromptingPipeline\Support\PromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
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
                'hand_back_to_user' => false,
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

    /**
     * The announced countdown is what the frontend animates. Deriving it from a
     * local flag alone made the round advertise — and animate "…" for — a turn
     * that was never queued once generation had been stopped mid-turn.
     */
    public function test_no_countdown_is_announced_when_generation_was_stopped(): void
    {
        Event::fake([MessageGenerated::class]);
        Queue::fake();
        ProjectJob::startGenerating($this->project->id);
        ProjectJob::markViewing($this->project->id);

        $routeJson = json_encode([
            'candidates' => ["E{$this->expert1->id}"],
            'directive' => [
                'role' => 'vertiefen',
                'agenda_step' => 'divergenz',
                'convergence_intent' => 'x',
                'hand_back_to_user' => false,
            ],
            'reasoning' => 'Test.',
        ]);

        $projectId = $this->project->id;
        $client = Mockery::mock(OpenAIClient::class);
        $client->shouldReceive('sendFast')->andReturnUsing(
            fn () => $routeJson,
            function () use ($projectId) {
                // Another client presses stop while this turn is still running.
                ProjectJob::stopGenerating($projectId);

                return "Alice antwortet kurz.\n---STEUERUNG---\nADRESSAT: none\nPAARTYP: Beitrag→Diskussion";
            },
        );
        $client->shouldReceive('sendSlow')->andReturn("GEDÄCHTNIS-UPDATE:\n[STAND]\nx\nBEITRAGSABSICHT: y.");

        $prompts = Mockery::mock(PromptBuilder::class);
        $prompts->shouldReceive('moderatorRoute')->andReturn('route-prompt');
        $prompts->shouldReceive('think')->andReturn('think-prompt');
        $prompts->shouldReceive('speak')->andReturn('speak-prompt');

        $this->instance(OpenAIClient::class, $client);
        $this->instance(PromptBuilder::class, $prompts);

        (new MessageGenerator($this->project->id))->handle();

        Event::assertDispatched(
            MessageGenerated::class,
            fn (MessageGenerated $e) => $e->nextTurnDelaySeconds === 0,
        );
        Queue::assertNotPushed(MessageGenerator::class);
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

        // The opening user message must already be answered, otherwise the
        // pending-user clamp keeps the floor with the experts.
        $this->project->addMessage('Erste Einschätzung.', $this->expert1);

        $routeJson = json_encode([
            'candidates' => ["E{$this->expert1->id}"],
            'directive' => [
                'role' => 'projektkontext_klaeren',
                'agenda_step' => 'konvergenz',
                'convergence_intent' => 'Präferenz klären',
                'hand_back_to_user' => true,
            ],
            'reasoning' => 'Nutzer fragen.',
        ]);

        $client = Mockery::mock(OpenAIClient::class);
        $client->shouldReceive('sendFast')->andReturn(
            $routeJson,
            "Welche Variante bevorzugst du?\n---STEUERUNG---\nADRESSAT: none\nPAARTYP: Frage→Antwort"
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
