<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Agents\ThinkAgent;
use App\Discussion\ParseFailure;
use App\Discussion\Stages\ThinkOnly;
use App\Discussion\Support\PromptRenderer;
use App\Discussion\TurnPayload;
use App\Discussion\Values\MemoryView;
use App\Events\PipelineStageChanged;
use App\Models\Expert;
use App\Models\Project;
use App\Models\Summary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Fakes\FakeAgents;
use Tests\TestCase;

class ThinkOnlyTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    /** @var list<Expert> */
    private array $experts;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([PipelineStageChanged::class]);

        $this->project = Project::factory()->create();
        $this->experts = Expert::factory()->count(3)->create()->all();

        foreach ($this->experts as $expert) {
            $this->project->addContributingExpert($expert);
        }
    }

    public function test_every_expert_thinks_and_none_gets_a_priority(): void
    {
        FakeAgents::always(ThinkAgent::class, ['thought' => 'Mein Gedanke.', 'priority' => 5]);

        $payload = app(ThinkOnly::class)->handle(new TurnPayload($this->project->fresh(), 1), fn (TurnPayload $payload) => $payload);

        foreach ($this->experts as $expert) {
            $this->assertSame('Mein Gedanke.', $payload->thoughtOf($expert)->text);
            $this->assertNull($payload->thoughtOf($expert)->priority, 'an answer that names a priority anyway is not read for it');
            $this->assertSame('Mein Gedanke.', Summary::where(['project_id' => $this->project->id, 'expert_id' => $expert->id])->value('content'));
        }
    }

    public function test_an_empty_thought_is_a_parse_failure(): void
    {
        FakeAgents::always(ThinkAgent::class, ['thought' => '  ']);

        $this->expectException(ParseFailure::class);

        app(ThinkOnly::class)->handle(new TurnPayload($this->project->fresh(), 1), fn (TurnPayload $payload) => $payload);
    }

    public function test_the_thought_prompt_is_the_bidding_prompt_without_what_asks_for_a_number(): void
    {
        $renderer = app(PromptRenderer::class);
        $data = [
            'project' => $this->project,
            'expert' => $this->experts[0],
            'memory' => new MemoryView([], '', ''),
            'lowest' => 1,
            'highest' => 5,
        ] + $renderer->participants($this->project);

        $bidding = $renderer->render('prompts.think.prioritize', $data);
        $expected = str_replace(
            'First continue your short-term memory, then report how urgently you need the floor right now.',
            'Continue your short-term memory.',
            preg_replace('/\n\nUrgency is a number.*$/s', '', $bidding),
        );
        $thought = $renderer->render('prompts.think.thought', $data);

        $this->assertSame(rtrim($expected), rtrim($thought));
        $this->assertStringNotContainsStringIgnoringCase('urgen', $thought);
    }
}
