<?php

namespace Tests\Unit\Pipeline;

use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use App\Services\AgentService;
use App\Services\OpenAIClient;
use App\Services\PromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AgentServiceTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;
    private Expert $expert;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->project = Project::withoutEvents(fn() => Project::create([
            'title'       => 'Test Project',
            'description' => 'Test',
            'settings'    => [],
            'user_id'     => $user->id,
        ]));
        $this->expert = Expert::factory()->create(['name' => 'Alice']);
        $this->project->addContributingExpert($this->expert);
    }

    public function test_think_extracts_and_saves_memory_update(): void
    {
        $raw = "Vorüberlegung.\nGEDÄCHTNIS-UPDATE:\nWas ich über den Nutzer weiß: Er liebt PHP.\nLetzter Gesprächsstand: Diskussion über Laravel.";

        $client  = Mockery::mock(OpenAIClient::class);
        $prompts = Mockery::mock(PromptBuilder::class);
        $client->shouldReceive('sendSlow')->once()->andReturn($raw);
        $prompts->shouldReceive('think')->once()->andReturn('prompt');

        (new AgentService($this->project, $client, $prompts))->think($this->expert);

        $content = $this->expert->thoughtsAbout($this->project)->content;
        $this->assertStringContainsString('Was ich über den Nutzer weiß', $content);
        $this->assertStringNotContainsString('Vorüberlegung', $content);
    }

    public function test_think_returns_full_raw_response(): void
    {
        $raw = "GEDÄCHTNIS-UPDATE:\nTest.";

        $client  = Mockery::mock(OpenAIClient::class);
        $prompts = Mockery::mock(PromptBuilder::class);
        $client->shouldReceive('sendSlow')->once()->andReturn($raw);
        $prompts->shouldReceive('think')->once()->andReturn('prompt');

        $result = (new AgentService($this->project, $client, $prompts))->think($this->expert);

        $this->assertSame($raw, $result);
    }

    public function test_think_saves_empty_string_when_marker_missing(): void
    {
        $client  = Mockery::mock(OpenAIClient::class);
        $prompts = Mockery::mock(PromptBuilder::class);
        $client->shouldReceive('sendSlow')->once()->andReturn('Keine Markierung hier.');
        $prompts->shouldReceive('think')->once()->andReturn('prompt');

        (new AgentService($this->project, $client, $prompts))->think($this->expert);

        $this->assertSame('', $this->expert->thoughtsAbout($this->project)->content);
    }

    public function test_think_and_prioritize_strips_prioritize_section_from_memory(): void
    {
        $raw = "THINK:\n  GEDÄCHTNIS-UPDATE:\n  Was ich über den Nutzer weiß: Er liebt PHP.\n\nPRIORITIZE:\n  PRIORITÄT: 4\n  ANTWORT-TYP: Frage\n  BEGRÜNDUNG: Relevant.";

        $client  = Mockery::mock(OpenAIClient::class);
        $prompts = Mockery::mock(PromptBuilder::class);
        $client->shouldReceive('sendSlow')->once()->andReturn($raw);
        $prompts->shouldReceive('thinkAndPrioritize')->once()->andReturn('prompt');

        (new AgentService($this->project, $client, $prompts))->thinkAndPrioritize($this->expert);

        $content = $this->expert->thoughtsAbout($this->project)->content;
        $this->assertStringContainsString('Was ich über den Nutzer weiß', $content);
        $this->assertStringNotContainsString('PRIORITÄT', $content);
        $this->assertStringNotContainsString('ANTWORT-TYP', $content);
    }

    public function test_speak_parses_content_and_metadata(): void
    {
        $raw = "Das ist mein Beitrag.\n\n[METADATEN — nicht sichtbar für andere]\nNEXT_SPEAKER: Bob\nADJACENCY_PAIR_TYPE: Frage→Antwort\nREASON: Eine Frage wurde gestellt.";

        $client  = Mockery::mock(OpenAIClient::class);
        $prompts = Mockery::mock(PromptBuilder::class);
        $client->shouldReceive('sendFast')->once()->andReturn($raw);
        $prompts->shouldReceive('speak')->once()->andReturn('prompt');

        $result = (new AgentService($this->project, $client, $prompts))->speak($this->expert, 'think output');

        $this->assertSame('Das ist mein Beitrag.', $result['content']);
        $this->assertSame('Bob', $result['next_speaker']);
        $this->assertSame('Frage→Antwort', $result['adjacency_pair_type']);
        $this->assertSame('Eine Frage wurde gestellt.', $result['reason']);
    }

    public function test_speak_handles_missing_metadata_gracefully(): void
    {
        $client  = Mockery::mock(OpenAIClient::class);
        $prompts = Mockery::mock(PromptBuilder::class);
        $client->shouldReceive('sendFast')->once()->andReturn('Nur Inhalt, keine Metadaten.');
        $prompts->shouldReceive('speak')->once()->andReturn('prompt');

        $result = (new AgentService($this->project, $client, $prompts))->speak($this->expert, 'think output');

        $this->assertSame('Nur Inhalt, keine Metadaten.', $result['content']);
        $this->assertSame('', $result['next_speaker']);
        $this->assertSame('', $result['adjacency_pair_type']);
        $this->assertSame('', $result['reason']);
    }

    public function test_speak_passes_moderation_note_to_prompt_builder(): void
    {
        $client  = Mockery::mock(OpenAIClient::class);
        $prompts = Mockery::mock(PromptBuilder::class);
        $client->shouldReceive('sendFast')->andReturn('Inhalt.');
        $prompts->shouldReceive('speak')
            ->with($this->project, $this->expert, 'think', 'mod note')
            ->once()
            ->andReturn('prompt');

        (new AgentService($this->project, $client, $prompts))->speak($this->expert, 'think', 'mod note');
    }
}
