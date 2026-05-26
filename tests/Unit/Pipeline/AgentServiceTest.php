<?php

namespace Tests\Unit\Pipeline;

use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use App\Services\PromptingPipeline\Support\AgentService;
use App\Services\Clients\OpenAIClient;
use App\Services\PromptingPipeline\Data\Directive;
use App\Services\PromptingPipeline\Support\PromptBuilder;
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

    public function test_think_returns_memory_and_beitragsabsicht(): void
    {
        $raw = "GEDÄCHTNIS-UPDATE:\n[STAND]\nDiskussion läuft.\nBEITRAGSABSICHT: Ich bringe ein Beispiel.";

        $client  = Mockery::mock(OpenAIClient::class);
        $prompts = Mockery::mock(PromptBuilder::class);
        $client->shouldReceive('sendSlow')->once()->andReturn($raw);
        $prompts->shouldReceive('think')->once()->andReturn('prompt');

        $result = (new AgentService($this->project, $client, $prompts))->think($this->expert);

        $this->assertArrayHasKey('memory', $result);
        $this->assertArrayHasKey('beitragsabsicht', $result);
        $this->assertStringContainsString('Diskussion läuft.', $result['memory']);
        $this->assertStringNotContainsString('BEITRAGSABSICHT', $result['memory']);
        $this->assertSame('Ich bringe ein Beispiel.', $result['beitragsabsicht']);
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

    public function test_think_prompt_does_not_call_llm(): void
    {
        $client  = Mockery::mock(OpenAIClient::class);
        $prompts = Mockery::mock(PromptBuilder::class);
        $prompts->shouldReceive('think')->once()->andReturn('rendered prompt');
        $client->shouldNotReceive('sendSlow');
        $client->shouldNotReceive('sendFast');

        $prompt = (new AgentService($this->project, $client, $prompts))->thinkPrompt($this->expert);

        $this->assertSame('rendered prompt', $prompt);
    }

    public function test_consume_think_persists_memory_block_without_llm_call(): void
    {
        $raw = "[NUTZER]\nLiebt PHP.\n[STAND]\nDiskussion über Laravel.";

        $client  = Mockery::mock(OpenAIClient::class);
        $prompts = Mockery::mock(PromptBuilder::class);
        $client->shouldNotReceive('sendSlow');
        $client->shouldNotReceive('sendFast');

        (new AgentService($this->project, $client, $prompts))
            ->consumeThink($this->expert, "GEDÄCHTNIS-UPDATE:\n{$raw}");

        $content = $this->expert->thoughtsAbout($this->project)->content;
        $this->assertStringContainsString('[NUTZER]', $content);
        $this->assertStringContainsString('Liebt PHP.', $content);
    }

    public function test_consume_think_does_not_overwrite_existing_memory_with_empty(): void
    {
        $existing = $this->expert->thoughtsAbout($this->project);
        $existing->content = "[NUTZER]\nVorhandene Notiz.";
        $existing->save();

        $client  = Mockery::mock(OpenAIClient::class);
        $prompts = Mockery::mock(PromptBuilder::class);

        (new AgentService($this->project, $client, $prompts))
            ->consumeThink($this->expert, 'kein marker hier');

        $this->assertStringContainsString('Vorhandene Notiz.', $this->expert->thoughtsAbout($this->project)->content);
    }

    public function test_speak_parses_content_and_steuerung_trailer(): void
    {
        $bob = Expert::factory()->create(['name' => 'Bob']);
        $this->project->addContributingExpert($bob);

        $raw = "Das ist mein Beitrag, Bob.\n---STEUERUNG---\nADRESSAT: E{$bob->id}\nPAARTYP: Frage→Antwort";

        $client  = Mockery::mock(OpenAIClient::class);
        $prompts = Mockery::mock(PromptBuilder::class);
        $client->shouldReceive('sendFast')->once()->andReturn($raw);
        $prompts->shouldReceive('speak')->once()->andReturn('prompt');

        $result = (new AgentService($this->project, $client, $prompts))
            ->speak($this->expert, ['memory' => 'm', 'beitragsabsicht' => 'b'], $this->directive());

        $this->assertSame('Das ist mein Beitrag, Bob.', $result['content']);
        $this->assertSame("E{$bob->id}", $result['adjacency_partner_token']);
        $this->assertSame('Frage→Antwort', $result['adjacency_pair_type']);
    }

    public function test_speak_handles_missing_trailer_gracefully(): void
    {
        $client  = Mockery::mock(OpenAIClient::class);
        $prompts = Mockery::mock(PromptBuilder::class);
        $client->shouldReceive('sendFast')->once()->andReturn('Nur Inhalt, keine Steuerung.');
        $prompts->shouldReceive('speak')->once()->andReturn('prompt');

        $result = (new AgentService($this->project, $client, $prompts))
            ->speak($this->expert, ['memory' => 'm', 'beitragsabsicht' => 'b'], $this->directive());

        $this->assertSame('Nur Inhalt, keine Steuerung.', $result['content']);
        $this->assertNull($result['adjacency_partner_token']);
        $this->assertNull($result['adjacency_pair_type']);
    }

    public function test_speak_rejects_unknown_partner_token_and_invalid_pair_type(): void
    {
        $raw = "Beitrag.\n---STEUERUNG---\nADRESSAT: E999999\nPAARTYP: Quatsch";

        $client  = Mockery::mock(OpenAIClient::class);
        $prompts = Mockery::mock(PromptBuilder::class);
        $client->shouldReceive('sendFast')->once()->andReturn($raw);
        $prompts->shouldReceive('speak')->once()->andReturn('prompt');

        $result = (new AgentService($this->project, $client, $prompts))
            ->speak($this->expert, ['memory' => 'm', 'beitragsabsicht' => 'b'], $this->directive());

        $this->assertSame('Beitrag.', $result['content']);
        $this->assertNull($result['adjacency_partner_token']);
        $this->assertNull($result['adjacency_pair_type']);
    }

    private function directive(): Directive
    {
        return new Directive(
            role: '',
            agendaStep: 'divergenz',
            convergenceIntent: '',
            addressUser: false,
        );
    }
}
