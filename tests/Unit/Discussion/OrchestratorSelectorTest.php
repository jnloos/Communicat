<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Agents\SelectorAgent;
use App\Discussion\Selectors\OrchestratorSelector;
use App\Discussion\TurnPayload;
use App\Discussion\Values\Selection;
use App\Models\Expert;
use App\Models\Project;
use App\Models\Summary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeAgents;
use Tests\TestCase;

class OrchestratorSelectorTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    /** @var list<Expert> */
    private array $experts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create(['seed' => 4711]);
        $this->experts = Expert::factory()->count(3)->create()->all();

        foreach ($this->experts as $expert) {
            $this->project->addContributingExpert($expert);
        }
    }

    private function orchestrate(): Selection
    {
        return app(OrchestratorSelector::class)->select(new TurnPayload($this->project, 1));
    }

    public function test_the_named_expert_gets_the_floor(): void
    {
        $token = $this->experts[1]->promptId;
        FakeAgents::always(SelectorAgent::class, ['speaker' => $token, 'reasoning' => 'Weil die Frage an sie ging.']);

        $selection = $this->orchestrate();

        $this->assertSame($this->experts[1]->id, $selection->speaker->id);
        $this->assertSame('Weil die Frage an sie ging.', $selection->reasoning);
        $this->assertFalse($selection->tieBroken);
        $this->assertSame('OrchestratorSelector', $selection->selector);
    }

    public function test_an_unknown_token_falls_back_to_the_tie_breaker(): void
    {
        FakeAgents::always(SelectorAgent::class, ['speaker' => 'E999', 'reasoning' => 'Irgendwer.']);

        $selection = $this->orchestrate();

        $this->assertTrue($selection->signals['fallback']);
        $this->assertTrue($selection->tieBroken);
        $this->assertContains($selection->speaker->id, array_map(fn (Expert $e) => $e->id, $this->experts));
    }

    public function test_a_missing_speaker_falls_back_to_the_tie_breaker(): void
    {
        FakeAgents::always(SelectorAgent::class, ['reasoning' => 'Ich denke, Alice sollte sprechen.']);

        $this->assertTrue($this->orchestrate()->signals['fallback']);
    }

    public function test_a_user_token_is_not_a_speaker(): void
    {
        // Only contributing experts speak; a user token must not be accepted.
        FakeAgents::always(SelectorAgent::class, ['speaker' => 'U1', 'reasoning' => 'Der Nutzer.']);

        $this->assertTrue($this->orchestrate()->signals['fallback']);
    }

    public function test_the_same_speaker_may_be_picked_twice_in_a_row(): void
    {
        // No balancing: repetition is the measurement, not an error.
        $token = $this->experts[0]->promptId;
        FakeAgents::always(SelectorAgent::class, ['speaker' => $token, 'reasoning' => 'Weiter so.']);

        $this->assertSame($this->experts[0]->id, $this->orchestrate()->speaker->id);
        $this->assertSame($this->experts[0]->id, $this->orchestrate()->speaker->id);
    }

    public function test_the_prompt_carries_the_history_but_no_private_thought(): void
    {
        $this->project->addMessage('Ein Beitrag von Alice.', $this->experts[0]);
        Summary::create([
            'project_id' => $this->project->id,
            'expert_id' => $this->experts[0]->id,
            'content' => 'GEHEIMER GEDANKE',
        ]);

        $token = $this->experts[0]->promptId;
        FakeAgents::always(SelectorAgent::class, ['speaker' => $token, 'reasoning' => 'Weiter so.']);

        $this->orchestrate();

        SelectorAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'Ein Beitrag von Alice.')
            && ! str_contains($prompt->prompt, 'GEHEIMER GEDANKE'));
    }
}
