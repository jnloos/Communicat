<?php

namespace Tests\Feature\Pipeline;

use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use App\Services\Clients\OpenAIClient;
use App\Services\PromptingPipeline\DiscussionPipeline;
use App\Services\PromptingPipeline\Support\PromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Memory is only written while an expert thinks, so a persona the moderator
 * rarely picks stops knowing what the discussion is about — in the logged
 * production project two of four experts still held a 35-character memory after
 * 80 messages, then entered the debate blind when finally chosen.
 */
class MemoryCatchUpTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Expert $candidate;

    private Expert $neglected;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['discussion.memory_catchup_per_turn' => 1, 'discussion.memory_catchup_min_backlog' => 1]);

        $this->user = User::factory()->create();
        $this->project = Project::withoutEvents(fn () => Project::create([
            'title' => 'Test', 'description' => 'Test', 'settings' => [], 'user_id' => $this->user->id,
        ]));
        $this->candidate = Expert::factory()->create(['name' => 'Alice']);
        $this->neglected = Expert::factory()->create(['name' => 'Bob']);
        $this->project->addContributingExpert($this->candidate);
        $this->project->addContributingExpert($this->neglected);

        $this->project->addMessage('Wie seht ihr das?', $this->user);
        $this->project->addMessage('Erste Einschätzung.', $this->candidate);
    }

    private function mockPrompts(): PromptBuilder
    {
        $prompts = Mockery::mock(PromptBuilder::class);
        $prompts->shouldReceive('moderatorRoute')->andReturn('route-prompt');
        $prompts->shouldReceive('moderatorSelect')->andReturn('select-prompt');
        $prompts->shouldReceive('think')->andReturn('think-prompt');
        $prompts->shouldReceive('speak')->andReturn('speak-prompt');

        return $prompts;
    }

    public function test_the_most_stale_expert_joins_the_think_batch_but_cannot_win(): void
    {
        $routeJson = json_encode([
            // Only Alice is a candidate; Bob is nobody's choice this turn.
            'candidates' => ["E{$this->candidate->id}"],
            'directive' => [
                'role' => 'vertiefen',
                'agenda_step' => 'divergenz',
                'convergence_intent' => 'x',
                'hand_back_to_user' => false,
            ],
            'reasoning' => 'Test.',
        ]);

        $think = "GEDÄCHTNIS-UPDATE:\n[STAND]\nStand der Dinge.\nBEITRAGSABSICHT: y.";

        $client = Mockery::mock(OpenAIClient::class);
        $client->shouldReceive('sendFast')->andReturn(
            $routeJson,
            json_encode(['winner' => "E{$this->candidate->id}", 'reasoning' => 'r']),
            "Ein Beitrag.\n---STEUERUNG---\nADRESSAT: none\nPAARTYP: Beitrag→Diskussion"
        );

        // Both experts think — the batch carries the catch-up prompt along, so
        // it costs tokens but no extra wall-clock time.
        $client->shouldReceive('sendManySlow')->once()
            ->with(Mockery::on(fn (array $prompts) => count($prompts) === 2), 'think')
            ->andReturn([
                $this->candidate->id => $think,
                $this->neglected->id => $think,
            ]);

        $this->instance(OpenAIClient::class, $client);
        $this->instance(PromptBuilder::class, $this->mockPrompts());

        (new DiscussionPipeline($this->project))->run();

        // The neglected expert's memory was refreshed …
        $this->assertStringContainsString(
            'Stand der Dinge.',
            (string) $this->neglected->thoughtsAbout($this->project)->content,
        );

        // … but the moderator's routing decision still stands: Alice speaks.
        $latest = $this->project->messages()->whereNotNull('expert_id')->latest('id')->first();
        $this->assertSame($this->candidate->id, $latest->expert_id);
    }

    public function test_catch_up_can_be_switched_off(): void
    {
        config(['discussion.memory_catchup_per_turn' => 0]);

        $routeJson = json_encode([
            'candidates' => ["E{$this->candidate->id}"],
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
            "Ein Beitrag.\n---STEUERUNG---\nADRESSAT: none\nPAARTYP: Beitrag→Diskussion"
        );
        // A single candidate and no catch-up means the inline path, not a batch.
        $client->shouldReceive('sendSlow')->once()
            ->andReturn("GEDÄCHTNIS-UPDATE:\n[STAND]\nx\nBEITRAGSABSICHT: y.");

        $this->instance(OpenAIClient::class, $client);
        $this->instance(PromptBuilder::class, $this->mockPrompts());

        (new DiscussionPipeline($this->project))->run();

        $this->assertSame('', (string) $this->neglected->thoughtsAbout($this->project)->content);
    }
}
