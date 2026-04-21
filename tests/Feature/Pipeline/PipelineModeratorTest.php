<?php

namespace Tests\Feature\Pipeline;

use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use App\Services\OpenAIClient;
use App\Services\PipelineModerator;
use App\Services\PromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PipelineModeratorTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;
    private Expert $expert1;
    private Expert $expert2;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->project = Project::withoutEvents(fn() => Project::create([
            'title'       => 'Test Project',
            'description' => 'Test',
            'settings'    => [],
            'user_id'     => $this->user->id,
        ]));
        $this->expert1 = Expert::factory()->create(['name' => 'Alice']);
        $this->expert2 = Expert::factory()->create(['name' => 'Bob']);
        $this->project->addContributingExpert($this->expert1);
        $this->project->addContributingExpert($this->expert2);
        $this->project->addMessage('Hallo, wie seht ihr das?', $this->user);
    }

    private function mockClient(): OpenAIClient
    {
        return Mockery::mock(OpenAIClient::class);
    }

    private function mockPrompts(): PromptBuilder
    {
        $prompts = Mockery::mock(PromptBuilder::class);
        $prompts->shouldReceive('moderatorRoute')->andReturn('route-prompt');
        $prompts->shouldReceive('think')->andReturn('think-prompt');
        $prompts->shouldReceive('thinkAndPrioritize')->andReturn('think-prio-prompt');
        $prompts->shouldReceive('moderatorSelect')->andReturn('select-prompt');
        $prompts->shouldReceive('speak')->andReturn('speak-prompt');
        return $prompts;
    }

    // -------------------------------------------------------------------------
    // PATH A
    // -------------------------------------------------------------------------

    public function test_path_a_creates_message_for_addressed_expert(): void
    {
        $client = $this->mockClient();
        $client->shouldReceive('sendFast')->andReturn(
            '{"path":"A","addressed_agent":"Alice","selected_agents":[],"reasoning":"Alice wurde angesprochen."}',
            "Alice antwortet.\n\n[METADATEN — nicht sichtbar für andere]\nNEXT_SPEAKER: Bob\nADJACENCY_PAIR_TYPE: Frage→Antwort\nREASON: Frage beantwortet."
        );
        $client->shouldReceive('sendSlow')->once()
            ->andReturn("GEDÄCHTNIS-UPDATE:\nWas ich über den Nutzer weiß: Freundlich.");

        $this->instance(OpenAIClient::class, $client);
        $this->instance(PromptBuilder::class, $this->mockPrompts());

        (new PipelineModerator($this->project))->run();

        $msg = $this->project->messages()->whereNotNull('expert_id')->latest()->first();
        $this->assertSame($this->expert1->id, $msg->expert_id);
        $this->assertSame('Alice antwortet.', $msg->content);
        $this->assertSame('Frage→Antwort', $msg->adjacency_pair_type);
        $this->assertSame('Bob', $msg->next_speaker);
    }

    public function test_path_a_saves_gedaechtnis_for_winner(): void
    {
        $client = $this->mockClient();
        $client->shouldReceive('sendFast')->andReturn(
            '{"path":"A","addressed_agent":"Alice","selected_agents":[],"reasoning":"Test."}',
            "Inhalt.\n\n[METADATEN — nicht sichtbar für andere]\nNEXT_SPEAKER: Nutzer\nADJACENCY_PAIR_TYPE: Zustimmung\nREASON: OK."
        );
        $client->shouldReceive('sendSlow')->once()
            ->andReturn("GEDÄCHTNIS-UPDATE:\nWas ich über den Nutzer weiß: Neugierig.");

        $this->instance(OpenAIClient::class, $client);
        $this->instance(PromptBuilder::class, $this->mockPrompts());

        (new PipelineModerator($this->project))->run();

        $summary = $this->expert1->thoughtsAbout($this->project);
        $this->assertStringContainsString('Neugierig', $summary->content);
    }

    public function test_path_a_updates_project_state(): void
    {
        $client = $this->mockClient();
        $client->shouldReceive('sendFast')->andReturn(
            '{"path":"A","addressed_agent":"Alice","selected_agents":[],"reasoning":"Test."}',
            "Inhalt.\n\n[METADATEN — nicht sichtbar für andere]\nNEXT_SPEAKER: Nutzer\nADJACENCY_PAIR_TYPE: Assertion→Reaktion\nREASON: OK."
        );
        $client->shouldReceive('sendSlow')->andReturn("GEDÄCHTNIS-UPDATE:\nTest.");

        $this->instance(OpenAIClient::class, $client);
        $this->instance(PromptBuilder::class, $this->mockPrompts());

        (new PipelineModerator($this->project))->run();

        $this->project->refresh();
        $settings = $this->project->settings;
        $this->assertSame('Alice', $settings['recent_speakers'][0]);
        $this->assertSame('Assertion→Reaktion', $settings['recent_response_types'][0]);
        $this->assertSame(0, $settings['silence_counters'][$this->expert1->id]);
        $this->assertGreaterThan(0, $settings['silence_counters'][$this->expert2->id]);
    }

    // -------------------------------------------------------------------------
    // PATH B
    // -------------------------------------------------------------------------

    public function test_path_b_runs_think_prioritize_for_all_selected_agents(): void
    {
        $thinkPrioResponse = "THINK:\n  GEDÄCHTNIS-UPDATE:\n  Was ich über den Nutzer weiß: Test.\n\nPRIORITIZE:\n  PRIORITÄT: 3\n  ANTWORT-TYP: Frage\n  BEGRÜNDUNG: Relevant.";

        $client = $this->mockClient();
        $client->shouldReceive('sendFast')->andReturn(
            '{"path":"B","addressed_agent":null,"selected_agents":["Alice","Bob"],"reasoning":"Offen."}',
            '{"winner":"Bob","reasoning":"Höchste Priorität."}',
            "Bob antwortet.\n\n[METADATEN — nicht sichtbar für andere]\nNEXT_SPEAKER: Alice\nADJACENCY_PAIR_TYPE: Assertion→Reaktion\nREASON: Bob hat Stellung bezogen."
        );
        $client->shouldReceive('sendManySlow')->once()->andReturn([
            'Alice' => $thinkPrioResponse,
            'Bob'   => $thinkPrioResponse,
        ]);

        $this->instance(OpenAIClient::class, $client);
        $this->instance(PromptBuilder::class, $this->mockPrompts());

        (new PipelineModerator($this->project))->run();

        $msg = $this->project->messages()->whereNotNull('expert_id')->latest()->first();
        $this->assertSame($this->expert2->id, $msg->expert_id);
        $this->assertSame('Bob antwortet.', $msg->content);
        $this->assertSame('Assertion→Reaktion', $msg->adjacency_pair_type);
    }

    public function test_path_b_falls_back_to_all_experts_when_selected_agents_empty(): void
    {
        $thinkPrioResponse = "THINK:\n  GEDÄCHTNIS-UPDATE:\n  Test.\n\nPRIORITIZE:\n  PRIORITÄT: 2\n  ANTWORT-TYP: Frage\n  BEGRÜNDUNG: Test.";

        $client = $this->mockClient();
        $client->shouldReceive('sendFast')->andReturn(
            '{"path":"B","addressed_agent":null,"selected_agents":[],"reasoning":"Offen."}',
            '{"winner":"Alice","reasoning":"Test."}',
            "Alice antwortet.\n\n[METADATEN — nicht sichtbar für andere]\nNEXT_SPEAKER: Nutzer\nADJACENCY_PAIR_TYPE: Frage→Antwort\nREASON: Test."
        );
        $client->shouldReceive('sendManySlow')->once()->andReturn([
            'Alice' => $thinkPrioResponse,
            'Bob'   => $thinkPrioResponse,
        ]);

        $this->instance(OpenAIClient::class, $client);
        $this->instance(PromptBuilder::class, $this->mockPrompts());

        (new PipelineModerator($this->project))->run();

        // Both experts were given think+prioritize (twice) and Alice won
        $msg = $this->project->messages()->whereNotNull('expert_id')->latest()->first();
        $this->assertSame($this->expert1->id, $msg->expert_id);
    }
}
