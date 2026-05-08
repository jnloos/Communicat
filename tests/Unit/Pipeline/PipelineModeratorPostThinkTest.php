<?php

namespace Tests\Unit\Pipeline;

use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use App\Services\AgentService;
use App\Services\OpenAIClient;
use App\Services\PipelineModerator;
use App\Services\PromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class PipelineModeratorPostThinkTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;
    private Expert $alice;
    private Expert $bob;
    private Expert $charlie;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->project = Project::withoutEvents(fn() => Project::create([
            'title'       => 'Test',
            'description' => 'Test',
            'settings'    => [],
            'user_id'     => $user->id,
        ]));

        $this->alice   = Expert::factory()->create(['name' => 'Alice']);
        $this->bob     = Expert::factory()->create(['name' => 'Bob']);
        $this->charlie = Expert::factory()->create(['name' => 'Charlie']);
        $this->project->addContributingExpert($this->alice);
        $this->project->addContributingExpert($this->bob);
        $this->project->addContributingExpert($this->charlie);
    }

    private function invokePostTurnThink(
        AgentService $agent,
        OpenAIClient $client,
        Expert $winner,
        array $route,
    ): void {
        $pipeline = new PipelineModerator($this->project);
        $method   = new ReflectionMethod($pipeline, 'postTurnThink');
        $method->setAccessible(true);
        $method->invoke($pipeline, $agent, $client, $winner, $route);
    }

    public function test_path_a_runs_think_for_every_non_winner(): void
    {
        $prompts = Mockery::mock(PromptBuilder::class);
        $prompts->shouldReceive('think')
            ->andReturnUsing(fn(Project $p, Expert $e) => "prompt:{$e->name}");

        $capturedKeys = null;
        $client = Mockery::mock(OpenAIClient::class);
        $client->shouldReceive('sendManySlow')
            ->once()
            ->with(
                Mockery::on(function (array $promptMap) use (&$capturedKeys) {
                    $capturedKeys = array_keys($promptMap);
                    return true;
                }),
                'post-turn-think',
            )
            ->andReturn([
                'Bob'     => "GEDÄCHTNIS-UPDATE:\n[NUTZER]\nBob-Notiz.",
                'Charlie' => "GEDÄCHTNIS-UPDATE:\n[NUTZER]\nCharlie-Notiz.",
            ]);

        $agent = new AgentService($this->project, $client, $prompts);
        $this->invokePostTurnThink($agent, $client, $this->alice, [
            'path'            => 'A',
            'addressed_agent' => 'Alice',
            'selected_agents' => [],
        ]);

        $this->assertEqualsCanonicalizing(['Bob', 'Charlie'], $capturedKeys);
        $this->assertNotContains('Alice', $capturedKeys);

        $this->assertStringContainsString('Bob-Notiz.', $this->bob->thoughtsAbout($this->project)->content);
        $this->assertStringContainsString('Charlie-Notiz.', $this->charlie->thoughtsAbout($this->project)->content);
    }

    public function test_path_b_skips_winner_and_already_thinking_candidates(): void
    {
        $prompts = Mockery::mock(PromptBuilder::class);
        $prompts->shouldReceive('think')
            ->andReturnUsing(fn(Project $p, Expert $e) => "prompt:{$e->name}");

        $capturedKeys = null;
        $client = Mockery::mock(OpenAIClient::class);
        $client->shouldReceive('sendManySlow')
            ->once()
            ->with(
                Mockery::on(function (array $promptMap) use (&$capturedKeys) {
                    $capturedKeys = array_keys($promptMap);
                    return true;
                }),
                'post-turn-think',
            )
            ->andReturn([
                'Charlie' => "GEDÄCHTNIS-UPDATE:\n[NUTZER]\nCharlie-Notiz.",
            ]);

        $agent = new AgentService($this->project, $client, $prompts);
        $this->invokePostTurnThink($agent, $client, $this->alice, [
            'path'            => 'B',
            'addressed_agent' => null,
            'selected_agents' => ['Alice', 'Bob'],
        ]);

        $this->assertSame(['Charlie'], $capturedKeys);
        $this->assertStringContainsString('Charlie-Notiz.', $this->charlie->thoughtsAbout($this->project)->content);
    }

    public function test_no_call_when_all_contributors_already_thought(): void
    {
        $prompts = Mockery::mock(PromptBuilder::class);
        $client  = Mockery::mock(OpenAIClient::class);
        $client->shouldNotReceive('sendManySlow');

        $agent = new AgentService($this->project, $client, $prompts);
        $this->invokePostTurnThink($agent, $client, $this->alice, [
            'path'            => 'B',
            'addressed_agent' => null,
            'selected_agents' => ['Alice', 'Bob', 'Charlie'],
        ]);
    }

    public function test_path_a_with_single_contributor_skips_call(): void
    {
        // Reduce project to a single contributor so only the winner exists.
        $this->project->removeContributingExpert($this->bob);
        $this->project->removeContributingExpert($this->charlie);

        $prompts = Mockery::mock(PromptBuilder::class);
        $client  = Mockery::mock(OpenAIClient::class);
        $client->shouldNotReceive('sendManySlow');

        $agent = new AgentService($this->project, $client, $prompts);
        $this->invokePostTurnThink($agent, $client, $this->alice, [
            'path'            => 'A',
            'addressed_agent' => 'Alice',
            'selected_agents' => [],
        ]);
    }

    public function test_speaker_name_match_is_case_insensitive(): void
    {
        $prompts = Mockery::mock(PromptBuilder::class);
        $prompts->shouldReceive('think')
            ->andReturnUsing(fn(Project $p, Expert $e) => "prompt:{$e->name}");

        $capturedKeys = null;
        $client = Mockery::mock(OpenAIClient::class);
        $client->shouldReceive('sendManySlow')
            ->once()
            ->with(
                Mockery::on(function (array $promptMap) use (&$capturedKeys) {
                    $capturedKeys = array_keys($promptMap);
                    return true;
                }),
                'post-turn-think',
            )
            ->andReturn([
                'Bob'     => "GEDÄCHTNIS-UPDATE:\n[NUTZER]\nBob.",
                'Charlie' => "GEDÄCHTNIS-UPDATE:\n[NUTZER]\nCharlie.",
            ]);

        $agent = new AgentService($this->project, $client, $prompts);
        $this->invokePostTurnThink($agent, $client, $this->alice, [
            'path'            => 'B',
            'addressed_agent' => null,
            'selected_agents' => ['ALICE'], // upper-cased on purpose
        ]);

        $this->assertNotContains('Alice', $capturedKeys);
    }
}
