<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Stages\Speak;
use App\Discussion\TurnPayload;
use App\Discussion\Values\Selection;
use App\Discussion\Values\Thought;
use App\Events\PipelineStageChanged;
use App\Llm\LlmException;
use App\Llm\LlmFactory;
use App\Models\Expert;
use App\Models\Message;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Fakes\FakeLlmClient;
use Tests\Fakes\FakeLlmFactory;
use Tests\TestCase;

class SpeakTest extends TestCase
{
    use RefreshDatabase;

    private FakeLlmClient $llm;

    private Project $project;

    private Expert $alice;

    private Expert $bob;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([PipelineStageChanged::class]);

        $this->llm = new FakeLlmClient;
        $this->app->instance(LlmFactory::class, new FakeLlmFactory($this->llm));

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
        $this->llm->push('speak', "Bob, woher nimmst du diese Zahl?\n---STEUERUNG---\nADRESSAT: E{$this->bob->id}\nPAARTYP: Frage→Antwort");

        $contribution = $this->speak($this->payload())->contribution();

        $this->assertSame('Bob, woher nimmst du diese Zahl?', $contribution->text);
        $this->assertSame("E{$this->bob->id}", $contribution->partnerToken);
        $this->assertSame(Message::PAIR_FRAGE_ANTWORT, $contribution->pairType);
    }

    public function test_a_missing_trailer_degrades_to_a_plenum_contribution(): void
    {
        $this->llm->push('speak', 'Ich sehe das anders.');

        $contribution = $this->speak($this->payload())->contribution();

        $this->assertSame('Ich sehe das anders.', $contribution->text);
        $this->assertNull($contribution->partnerToken);
        $this->assertNull($contribution->pairType);
    }

    public function test_unknown_addressees_and_pair_types_are_dropped(): void
    {
        $this->llm->push('speak', "Text.\n---STEUERUNG---\nADRESSAT: E999\nPAARTYP: Abschluss→Nutzer");

        $contribution = $this->speak($this->payload())->contribution();

        $this->assertNull($contribution->partnerToken);
        $this->assertNull($contribution->pairType);
    }

    public function test_an_empty_visible_text_is_a_parse_failure(): void
    {
        $this->llm->push('speak', "---STEUERUNG---\nADRESSAT: none\nPAARTYP: Beitrag→Diskussion");

        try {
            $this->speak($this->payload());
            $this->fail('expected an LlmException');
        } catch (LlmException $e) {
            $this->assertSame(LlmException::KIND_PARSE, $e->kind);
        }
    }

    public function test_the_prompt_carries_a_proposal_when_the_speaker_made_one(): void
    {
        $this->llm->push('speak', 'Text.');

        $this->speak($this->payload(new Thought($this->alice->id, 'Gedanke', proposal: 'Mein Entwurf lautet so.')));

        $request = $this->llm->requestsFor('speak')[0];

        $this->assertSame($this->alice->id, $request->expertId);
        $this->assertStringContainsString('Mein Entwurf lautet so.', $request->prompt);
        $this->assertStringContainsString(Speak::MARKER_CONTROL, $request->prompt);
    }
}
