<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Agents\SpeakAgent;
use App\Discussion\ParseFailure;
use App\Discussion\Schemas\ContributionSchema;
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

    public function test_it_takes_the_contribution_and_the_addressee_from_the_answer(): void
    {
        FakeAgents::always(SpeakAgent::class, ['contribution' => 'Bob, woher nimmst du diese Zahl?', 'addressee' => "E{$this->bob->id}"]);

        $contribution = $this->speak($this->payload())->contribution();

        $this->assertSame('Bob, woher nimmst du diese Zahl?', $contribution->text);
        $this->assertSame("E{$this->bob->id}", $contribution->addresseeToken);
    }

    public function test_the_contribution_is_taken_verbatim(): void
    {
        // It is what the study counts, in words and characters, so nothing may be
        // trimmed out of it -- not even something that looks like an old marker.
        FakeAgents::always(SpeakAgent::class, [
            'contribution' => "Erstens: die Zahl.\nZweitens: die Quelle.",
            'addressee' => null,
        ]);

        $this->assertSame(
            "Erstens: die Zahl.\nZweitens: die Quelle.",
            $this->speak($this->payload())->contribution()->text,
        );
    }

    public function test_a_null_addressee_is_a_contribution_to_the_group(): void
    {
        FakeAgents::always(SpeakAgent::class, ['contribution' => 'Ich sehe das anders.', 'addressee' => null]);

        $contribution = $this->speak($this->payload())->contribution();

        $this->assertSame('Ich sehe das anders.', $contribution->text);
        $this->assertNull($contribution->addresseeToken);
    }

    public function test_an_unknown_addressee_is_dropped(): void
    {
        FakeAgents::always(SpeakAgent::class, ['contribution' => 'Text.', 'addressee' => 'E999']);

        $contribution = $this->speak($this->payload())->contribution();

        $this->assertNull($contribution->addresseeToken);
    }

    public function test_an_empty_contribution_is_a_parse_failure(): void
    {
        FakeAgents::always(SpeakAgent::class, ['contribution' => '   ', 'addressee' => null]);

        $this->expectException(ParseFailure::class);

        $this->speak($this->payload());
    }

    public function test_the_prompt_carries_a_proposal_when_the_speaker_made_one(): void
    {
        FakeAgents::always(SpeakAgent::class, ['contribution' => 'Text.', 'addressee' => null]);

        $this->speak($this->payload(new Thought($this->alice->id, 'Gedanke', proposal: 'Mein Entwurf lautet so.')));

        SpeakAgent::assertPrompted(fn ($prompt) => $prompt->agent->expertId === $this->alice->id
            && str_contains($prompt->prompt, 'Mein Entwurf lautet so.')
            && $prompt->agent->schema === ContributionSchema::class);
    }
}
