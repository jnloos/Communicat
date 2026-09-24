<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Agents\ThinkAgent;
use App\Discussion\ParseFailure;
use App\Discussion\Stages\ThinkAndPropose;
use App\Discussion\TurnPayload;
use App\Events\PipelineStageChanged;
use App\Models\Expert;
use App\Models\Project;
use App\Models\Summary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Fakes\FakeAgents;
use Tests\TestCase;

class ThinkAndProposeTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([PipelineStageChanged::class]);

        $this->project = Project::factory()->create();
        foreach (Expert::factory()->count(3)->create() as $expert) {
            $this->project->addContributingExpert($expert);
        }
    }

    // Not run(): TestCase::run() is final and cannot be overridden.
    private function propose(): TurnPayload
    {
        $payload = new TurnPayload($this->project, 1);

        return app(ThinkAndPropose::class)->handle($payload, fn (TurnPayload $p) => $p);
    }

    public function test_every_expert_delivers_a_thought_and_a_draft(): void
    {
        FakeAgents::always(ThinkAgent::class, "GEDANKE: Mein Gedanke.\nENTWURF: Mein Entwurf lautet so.");

        $payload = $this->propose();

        $this->assertCount(3, $payload->thoughts());

        foreach ($this->project->contributingExperts() as $expert) {
            $thought = $payload->thoughtOf($expert);
            $this->assertSame('Mein Gedanke.', $thought->text);
            $this->assertSame('Mein Entwurf lautet so.', $thought->proposal);
        }
    }

    public function test_the_short_term_memory_of_every_agent_is_written(): void
    {
        FakeAgents::always(ThinkAgent::class, "GEDANKE: Mein Gedanke.\nENTWURF: Entwurf.");

        $this->propose();

        $this->assertSame(3, Summary::where('project_id', $this->project->id)->count());
    }

    public function test_a_missing_draft_leaves_the_proposal_null(): void
    {
        FakeAgents::always(ThinkAgent::class, 'GEDANKE: Mein Gedanke.');

        $payload = $this->propose();

        $this->assertNull($payload->thoughtOf($this->project->contributingExperts()->first())->proposal);
    }

    public function test_a_missing_thought_marker_is_a_parse_failure(): void
    {
        FakeAgents::always(ThinkAgent::class, 'ENTWURF: Nur ein Entwurf.');

        $this->expectException(ParseFailure::class);

        $this->propose();
    }
}
