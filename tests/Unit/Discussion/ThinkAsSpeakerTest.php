<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Agents\ThinkAgent;
use App\Discussion\ParseFailure;
use App\Discussion\Stages\ThinkAsSpeaker;
use App\Discussion\Support\Thinking;
use App\Discussion\TurnPayload;
use App\Discussion\Values\Selection;
use App\Events\PipelineStageChanged;
use App\Models\Expert;
use App\Models\Project;
use App\Models\Summary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Fakes\FakeAgents;
use Tests\TestCase;

class ThinkAsSpeakerTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Expert $speaker;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([PipelineStageChanged::class]);

        FakeAgents::always(ThinkAgent::class, 'GEDANKE: Ich will etwas beitragen.');

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
        FakeAgents::always(ThinkAgent::class, 'GEDANKE: Bob übersieht die Kosten. Ich will ein Zahlenbeispiel bringen.');

        $payload = $this->payload();
        app(ThinkAsSpeaker::class)->handle($payload, fn (TurnPayload $p) => $p);

        ThinkAgent::assertPromptedTimes(1);
        ThinkAgent::assertPrompted(fn ($prompt) => $prompt->agent->expertId === $this->speaker->id);
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
        FakeAgents::always(ThinkAgent::class, 'GEDANKE: neu');

        app(ThinkAsSpeaker::class)->handle($this->payload(), fn (TurnPayload $p) => $p);

        ThinkAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'Du bist Alice'));
        ThinkAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'KI an Schulen'));
        ThinkAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'Mein alter Gedanke.'));
        ThinkAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, ThinkAsSpeaker::MARKER_THOUGHT));
    }

    public function test_a_second_think_overwrites_the_thought(): void
    {
        FakeAgents::inOrder(ThinkAgent::class, ['GEDANKE: erster', 'GEDANKE: zweiter']);

        app(ThinkAsSpeaker::class)->handle($this->payload(), fn (TurnPayload $p) => $p);
        app(ThinkAsSpeaker::class)->handle($this->payload(), fn (TurnPayload $p) => $p);

        $this->assertSame(1, Summary::count());
        $this->assertSame('zweiter', Summary::sole()->content);
    }

    public function test_a_missing_marker_is_a_parse_failure_and_keeps_the_old_thought(): void
    {
        Summary::create(['project_id' => $this->project->id, 'expert_id' => $this->speaker->id, 'content' => 'bleibt']);
        FakeAgents::always(ThinkAgent::class, 'Ich halte mich nicht an das Format.');

        try {
            app(ThinkAsSpeaker::class)->handle($this->payload(), fn (TurnPayload $p) => $p);
            $this->fail('expected a ParseFailure');
        } catch (ParseFailure $e) {
            $this->assertStringContainsString(ThinkAsSpeaker::MARKER_THOUGHT, $e->getMessage());
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
