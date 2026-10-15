<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Agents\JudgeAgent;
use App\Discussion\Selectors\JudgeSelector;
use App\Discussion\TurnPayload;
use App\Discussion\Values\Selection;
use App\Discussion\Values\Thought;
use App\Models\Expert;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeAgents;
use Tests\TestCase;

class JudgeSelectorTest extends TestCase
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

    /** @param  list<int>|null  $drafting  indexes of the experts who handed in a draft; null means all */
    private function judge(?array $drafting = null): Selection
    {
        $payload = new TurnPayload($this->project, 1);

        foreach ($this->experts as $index => $expert) {
            $drafted = $drafting === null || in_array($index, $drafting, true);
            $payload->addThought(new Thought($expert->id, 'Gedanke', proposal: $drafted ? "Entwurf {$index}" : null));
        }

        return app(JudgeSelector::class)->select($payload);
    }

    public function test_the_chosen_draft_gets_the_floor(): void
    {
        FakeAgents::always(JudgeAgent::class, [
            'speaker' => $this->experts[1]->promptId,
            'reasoning' => 'Der zweite Entwurf ist am konkretesten.',
        ]);

        $selection = $this->judge();

        $this->assertSame($this->experts[1]->id, $selection->speaker->id);
        $this->assertSame('Der zweite Entwurf ist am konkretesten.', $selection->reasoning);
        $this->assertSame('JudgeSelector', $selection->selector);
        $this->assertSame(['token' => $this->experts[1]->promptId, 'fallback' => false], $selection->signals);
        $this->assertFalse($selection->tieBroken);
    }

    /**
     * The judge picks one draft, so there is nothing to tie: a scale let two
     * drafts share the top mark, and the floor then went to a random draw in up
     * to half the turns of a cell -- the study's null hypothesis standing in for
     * a decision.
     */
    public function test_only_the_drafts_on_the_table_can_be_chosen(): void
    {
        FakeAgents::always(JudgeAgent::class, ['speaker' => $this->experts[0]->promptId, 'reasoning' => 'Nur zwei Entwürfe.']);

        $this->judge(drafting: [0, 2]);

        JudgeAgent::assertPrompted(fn ($prompt) => $prompt->agent->schemaArgs === [[
            $this->experts[0]->promptId,
            $this->experts[2]->promptId,
        ]]);
    }

    public function test_an_unknown_token_falls_back_to_the_tie_breaker_among_the_drafters(): void
    {
        FakeAgents::always(JudgeAgent::class, ['speaker' => 'E999', 'reasoning' => 'Unlesbar.']);

        $selection = $this->judge(drafting: [0, 1]);

        $this->assertSame(['token' => 'E999', 'fallback' => true], $selection->signals);
        $this->assertTrue($selection->tieBroken);
        $this->assertContains($selection->speaker->id, [$this->experts[0]->id, $this->experts[1]->id]);
    }

    public function test_a_speaker_that_is_not_a_string_falls_back_to_the_tie_breaker(): void
    {
        FakeAgents::always(JudgeAgent::class, ['speaker' => 42, 'reasoning' => '-']);

        $selection = $this->judge(drafting: [0, 1]);

        $this->assertSame(['token' => '', 'fallback' => true], $selection->signals);
        $this->assertTrue($selection->tieBroken);
    }

    /** A token for someone who handed in no draft cannot win either: there is nothing of theirs to judge. */
    public function test_a_token_without_a_draft_is_a_fallback(): void
    {
        FakeAgents::always(JudgeAgent::class, ['speaker' => $this->experts[2]->promptId, 'reasoning' => 'Daneben.']);

        $selection = $this->judge(drafting: [0, 1]);

        $this->assertTrue($selection->signals['fallback']);
        $this->assertNotSame($this->experts[2]->id, $selection->speaker->id);
    }

    /** Without a single draft there is nothing to judge, so no call is made at all. */
    public function test_no_draft_at_all_skips_the_judge(): void
    {
        FakeAgents::always(JudgeAgent::class, ['speaker' => $this->experts[0]->promptId, 'reasoning' => '-']);

        $selection = $this->judge(drafting: []);

        JudgeAgent::assertNeverPrompted();
        $this->assertSame(['token' => null, 'fallback' => true], $selection->signals);
        $this->assertTrue($selection->tieBroken);
    }

    /**
     * The judge sees who holds which role, as the orchestrator does. Status is the
     * study's manipulation, and a judge that only met it through the drafts could
     * not show whether it favours a title itself.
     */
    public function test_the_prompt_lists_the_participants_with_their_roles(): void
    {
        $this->experts[0]->update(['role' => 'Teacher']);
        FakeAgents::always(JudgeAgent::class, ['speaker' => $this->experts[0]->promptId, 'reasoning' => '-']);

        $this->judge();

        JudgeAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, '=== PARTICIPANTS')
            && str_contains($prompt->prompt, "{$this->experts[0]->name} [{$this->experts[0]->promptId}] (Teacher)"));
    }

    public function test_the_prompt_shows_the_drafts_with_their_author(): void
    {
        FakeAgents::always(JudgeAgent::class, ['speaker' => $this->experts[0]->promptId, 'reasoning' => '-']);

        $this->judge();

        JudgeAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'Entwurf 0')
            && str_contains($prompt->prompt, 'Entwurf 1')
            && str_contains($prompt->prompt, $this->experts[0]->promptId));
    }
}
