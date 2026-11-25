<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Agents\ThinkAgent;
use App\Discussion\ParseFailure;
use App\Discussion\Stages\ThinkAndPrioritize;
use App\Discussion\TurnPayload;
use App\Events\PipelineStageChanged;
use App\Models\Expert;
use App\Models\Project;
use App\Models\Summary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Fakes\FakeAgents;
use Tests\TestCase;

class ThinkAndPrioritizeTest extends TestCase
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

    private function prioritize(): TurnPayload
    {
        $payload = new TurnPayload($this->project, 1);

        return app(ThinkAndPrioritize::class)->handle($payload, fn (TurnPayload $p) => $p);
    }

    public function test_every_contributing_expert_bids(): void
    {
        FakeAgents::always(ThinkAgent::class, ['thought' => 'Mein Gedanke.', 'priority' => 4]);

        $payload = $this->prioritize();

        $this->assertCount(3, $payload->thoughts());

        foreach ($this->project->contributingExperts() as $expert) {
            $thought = $payload->thoughtOf($expert);
            $this->assertNotNull($thought);
            $this->assertSame('Mein Gedanke.', $thought->text);
            $this->assertSame(4, $thought->priority);
        }
    }

    public function test_the_short_term_memory_of_every_agent_is_written(): void
    {
        FakeAgents::always(ThinkAgent::class, ['thought' => 'Mein Gedanke.', 'priority' => 2]);

        $this->prioritize();

        $this->assertSame(3, Summary::where('project_id', $this->project->id)->count());
    }

    public function test_a_missing_priority_becomes_null_for_the_selector_to_handle(): void
    {
        FakeAgents::always(ThinkAgent::class, ['thought' => 'Mein Gedanke.']);

        $payload = $this->prioritize();

        $this->assertNull($payload->thoughtOf($this->project->contributingExperts()->first())->priority);
    }

    public function test_zero_is_a_readable_priority(): void
    {
        FakeAgents::always(ThinkAgent::class, ['thought' => 'Mein Gedanke.', 'priority' => 0]);

        $payload = $this->prioritize();

        $this->assertSame(0, $payload->thoughtOf($this->project->contributingExperts()->first())->priority);
    }

    public function test_a_priority_outside_zero_to_nine_counts_as_unreadable(): void
    {
        FakeAgents::always(ThinkAgent::class, ['thought' => 'Mein Gedanke.', 'priority' => 10]);

        $payload = $this->prioritize();

        $this->assertNull($payload->thoughtOf($this->project->contributingExperts()->first())->priority);
    }

    public function test_a_missing_thought_is_a_parse_failure(): void
    {
        FakeAgents::always(ThinkAgent::class, ['priority' => 3]);

        $this->expectException(ParseFailure::class);

        $this->prioritize();
    }
}
