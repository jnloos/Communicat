<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Stages\ThinkAsSpeaker;
use App\Discussion\Support\Thinking;
use App\Discussion\TurnPayload;
use App\Discussion\Values\Selection;
use App\Events\PipelineStageChanged;
use App\Llm\LlmException;
use App\Llm\LlmFactory;
use App\Models\Expert;
use App\Models\Project;
use App\Models\Summary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Fakes\FakeLlmClient;
use Tests\Fakes\FakeLlmFactory;
use Tests\TestCase;

class ThinkAsSpeakerTest extends TestCase
{
    use RefreshDatabase;

    private FakeLlmClient $llm;

    private Project $project;

    private Expert $speaker;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([PipelineStageChanged::class]);

        $this->llm = new FakeLlmClient;
        $this->app->instance(LlmFactory::class, new FakeLlmFactory($this->llm));

        $this->project = Project::factory()->create(['title' => 'KI an Schulen']);
        $this->speaker = Expert::factory()->create(['name' => 'Alice']);
        $this->project->addContributingExpert($this->speaker);
        $this->project->addContributingExpert(Expert::factory()->create(['name' => 'Bob']));
    }

    private function payload(): TurnPayload
    {
        $payload = new TurnPayload($this->project, 1, null);
        $payload->select(new Selection($this->project->contributingExperts()->first(), 'RoundRobinSelector'));

        return $payload;
    }

    public function test_only_the_selected_speaker_thinks_and_the_thought_is_kept(): void
    {
        $this->llm->push('think', 'GEDANKE: Bob übersieht die Kosten. Ich will ein Zahlenbeispiel bringen.');

        $payload = $this->payload();
        app(ThinkAsSpeaker::class)->handle($payload, fn (TurnPayload $p) => $p);

        $this->assertCount(1, $this->llm->requestsFor('think'));
        $this->assertSame($this->speaker->id, $this->llm->requestsFor('think')[0]->expertId);
        $this->assertSame(
            'Bob übersieht die Kosten. Ich will ein Zahlenbeispiel bringen.',
            $payload->thoughtOf($this->speaker)->text,
        );
        $this->assertSame(
            'Bob übersieht die Kosten. Ich will ein Zahlenbeispiel bringen.',
            Summary::where('expert_id', $this->speaker->id)->value('content'),
        );
    }

    public function test_the_prompt_carries_persona_memory_and_the_marker(): void
    {
        Summary::create(['project_id' => $this->project->id, 'expert_id' => $this->speaker->id, 'content' => 'Mein alter Gedanke.']);
        $this->llm->push('think', 'GEDANKE: neu');

        app(ThinkAsSpeaker::class)->handle($this->payload(), fn (TurnPayload $p) => $p);

        $prompt = $this->llm->requestsFor('think')[0]->prompt;

        $this->assertStringContainsString('Du bist Alice', $prompt);
        $this->assertStringContainsString('KI an Schulen', $prompt);
        $this->assertStringContainsString('Mein alter Gedanke.', $prompt);
        $this->assertStringContainsString(ThinkAsSpeaker::MARKER_THOUGHT, $prompt);
    }

    public function test_a_second_think_overwrites_the_thought(): void
    {
        $this->llm->push('think', 'GEDANKE: erster', 'GEDANKE: zweiter');

        app(ThinkAsSpeaker::class)->handle($this->payload(), fn (TurnPayload $p) => $p);
        app(ThinkAsSpeaker::class)->handle($this->payload(), fn (TurnPayload $p) => $p);

        $this->assertSame(1, Summary::count());
        $this->assertSame('zweiter', Summary::sole()->content);
    }

    public function test_a_missing_marker_is_a_parse_failure_and_keeps_the_old_thought(): void
    {
        Summary::create(['project_id' => $this->project->id, 'expert_id' => $this->speaker->id, 'content' => 'bleibt']);
        $this->llm->push('think', 'Ich halte mich nicht an das Format.');

        try {
            app(ThinkAsSpeaker::class)->handle($this->payload(), fn (TurnPayload $p) => $p);
            $this->fail('expected an LlmException');
        } catch (LlmException $e) {
            $this->assertSame(LlmException::KIND_PARSE, $e->kind);
        }

        $this->assertSame('bleibt', Summary::sole()->content);
    }

    public function test_section_reads_between_two_markers(): void
    {
        $text = "Vorrede\nGEDANKE: mein Gedanke\nPRIORITÄT: 4";

        $this->assertSame('mein Gedanke', Thinking::section($text, 'GEDANKE:', 'PRIORITÄT:'));
        $this->assertSame('4', Thinking::section($text, 'PRIORITÄT:'));
        $this->assertSame('', Thinking::section($text, 'FEHLT:'));
    }
}
