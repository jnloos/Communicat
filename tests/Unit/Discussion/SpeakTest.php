<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Agents\SpeakAgent;
use App\Discussion\ParseFailure;
use App\Discussion\Stages\Speak;
use App\Discussion\TurnPayload;
use App\Discussion\Values\Selection;
use App\Discussion\Values\Thought;
use App\Events\PipelineStageChanged;
use App\Models\Expert;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Fakes\FakeAgents;
use Tests\TestCase;

class SpeakTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Expert $alice;

    private Expert $bob;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([PipelineStageChanged::class]);

        $this->project = Project::factory()->create();
        $this->alice = Expert::factory()->create(['name' => 'Alice']);
        $this->bob = Expert::factory()->create(['name' => 'Bob']);
        $this->project->addContributingExpert($this->alice);
        $this->project->addContributingExpert($this->bob);
    }

    private function payload(?Thought $thought = null): TurnPayload
    {
        $payload = new TurnPayload($this->project, 1);
        $payload->select(new Selection($this->project->contributingExperts()->first(), 'RoundRobinSelector'));

        if ($thought !== null) {
            $payload->addThought($thought);
        }

        return $payload;
    }

    private function speak(TurnPayload $payload): TurnPayload
    {
        return app(Speak::class)->handle($payload, fn (TurnPayload $p) => $p);
    }

    public function test_splits_the_visible_text_from_the_control_trailer(): void
    {
        FakeAgents::always(SpeakAgent::class, "Bob, woher nimmst du diese Zahl?\n---STEUERUNG---\nADRESSAT: E{$this->bob->id}");

        $contribution = $this->speak($this->payload())->contribution();

        $this->assertSame('Bob, woher nimmst du diese Zahl?', $contribution->text);
        $this->assertSame("E{$this->bob->id}", $contribution->addresseeToken);
    }

    public function test_a_leftover_pair_type_line_is_ignored(): void
    {
        // Ein Modell, das noch das alte Format liefert, darf keinen Fehler auslösen:
        // der Trailer wird nur nach ADRESSAT durchsucht, alles andere fällt weg.
        FakeAgents::always(SpeakAgent::class, "Text.\n---STEUERUNG---\nADRESSAT: E{$this->bob->id}\nPAARTYP: Frage→Antwort");

        $contribution = $this->speak($this->payload())->contribution();

        $this->assertSame('Text.', $contribution->text);
        $this->assertSame("E{$this->bob->id}", $contribution->addresseeToken);
    }

    public function test_a_missing_trailer_degrades_to_a_plenum_contribution(): void
    {
        FakeAgents::always(SpeakAgent::class, 'Ich sehe das anders.');

        $contribution = $this->speak($this->payload())->contribution();

        $this->assertSame('Ich sehe das anders.', $contribution->text);
        $this->assertNull($contribution->addresseeToken);
    }

    public function test_an_unknown_addressee_is_dropped(): void
    {
        FakeAgents::always(SpeakAgent::class, "Text.\n---STEUERUNG---\nADRESSAT: E999");

        $contribution = $this->speak($this->payload())->contribution();

        $this->assertNull($contribution->addresseeToken);
    }

    public function test_an_empty_visible_text_is_a_parse_failure(): void
    {
        FakeAgents::always(SpeakAgent::class, "---STEUERUNG---\nADRESSAT: none");

        $this->expectException(ParseFailure::class);

        $this->speak($this->payload());
    }

    public function test_the_prompt_carries_a_proposal_when_the_speaker_made_one(): void
    {
        FakeAgents::always(SpeakAgent::class, 'Text.');

        $this->speak($this->payload(new Thought($this->alice->id, 'Gedanke', proposal: 'Mein Entwurf lautet so.')));

        SpeakAgent::assertPrompted(fn ($prompt) => $prompt->agent->expertId === $this->alice->id
            && str_contains($prompt->prompt, 'Mein Entwurf lautet so.')
            && str_contains($prompt->prompt, Speak::MARKER_CONTROL));
    }
}
