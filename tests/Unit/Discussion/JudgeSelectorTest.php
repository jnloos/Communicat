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

    private function judge(): Selection
    {
        $payload = new TurnPayload($this->project, 1);

        foreach ($this->experts as $index => $expert) {
            $payload->addThought(new Thought($expert->id, 'Gedanke', proposal: "Entwurf {$index}"));
        }

        return app(JudgeSelector::class)->select($payload);
    }

    public function test_the_highest_score_gets_the_floor(): void
    {
        FakeAgents::always(JudgeAgent::class, [
            'scores' => [
                ['expert' => $this->experts[0]->promptId, 'score' => 4],
                ['expert' => $this->experts[1]->promptId, 'score' => 9],
                ['expert' => $this->experts[2]->promptId, 'score' => 6],
            ],
            'reasoning' => 'Der zweite Entwurf ist am konkretesten.',
        ]);

        $selection = $this->judge();

        $this->assertSame($this->experts[1]->id, $selection->speaker->id);
        $this->assertSame('Der zweite Entwurf ist am konkretesten.', $selection->reasoning);
        $this->assertSame('JudgeSelector', $selection->selector);
    }

    public function test_all_scores_are_recorded_in_the_signals(): void
    {
        FakeAgents::always(JudgeAgent::class, [
            'scores' => [
                ['expert' => $this->experts[0]->promptId, 'score' => 4],
                ['expert' => $this->experts[1]->promptId, 'score' => 9],
                ['expert' => $this->experts[2]->promptId, 'score' => 6],
            ],
            'reasoning' => 'Der zweite Entwurf ist am konkretesten.',
        ]);

        $this->assertSame([
            $this->experts[0]->promptId => 4,
            $this->experts[1]->promptId => 9,
            $this->experts[2]->promptId => 6,
        ], $this->judge()->signals['scores']);
    }

    public function test_the_scores_decide_even_if_the_text_suggests_otherwise(): void
    {
        // The judge names no winner, so prose and numbers cannot contradict each
        // other — the rule is the same one the bids follow.
        FakeAgents::always(JudgeAgent::class, [
            'scores' => [
                ['expert' => $this->experts[0]->promptId, 'score' => 2],
                ['expert' => $this->experts[1]->promptId, 'score' => 8],
            ],
            'reasoning' => 'Eigentlich finde ich den ersten Entwurf besser.',
        ]);

        $this->assertSame($this->experts[1]->id, $this->judge()->speaker->id);
    }

    public function test_an_unscored_expert_is_recorded_as_a_fallback(): void
    {
        FakeAgents::always(JudgeAgent::class, [
            'scores' => [['expert' => $this->experts[0]->promptId, 'score' => 5]],
            'reasoning' => 'Nur einer hat etwas geliefert.',
        ]);

        $selection = $this->judge();

        $this->assertSame($this->experts[0]->id, $selection->speaker->id);
        $this->assertContains($this->experts[1]->promptId, $selection->signals['fallbacks']);
        $this->assertContains($this->experts[2]->promptId, $selection->signals['fallbacks']);
    }

    public function test_no_usable_score_falls_back_to_the_tie_breaker(): void
    {
        FakeAgents::always(JudgeAgent::class, ['scores' => [], 'reasoning' => 'Ich kann mich nicht entscheiden.']);

        $selection = $this->judge();

        $this->assertTrue($selection->signals['fallback']);
        $this->assertTrue($selection->tieBroken);
    }

    public function test_a_tie_between_two_scores_goes_to_the_tie_breaker(): void
    {
        FakeAgents::always(JudgeAgent::class, [
            'scores' => [
                ['expert' => $this->experts[0]->promptId, 'score' => 7],
                ['expert' => $this->experts[1]->promptId, 'score' => 7],
            ],
            'reasoning' => 'Beide gleich stark.',
        ]);

        $selection = $this->judge();

        $this->assertTrue($selection->tieBroken);
        $this->assertContains($selection->speaker->id, [$this->experts[0]->id, $this->experts[1]->id]);
    }

    public function test_the_prompt_shows_the_drafts_with_their_author(): void
    {
        FakeAgents::always(JudgeAgent::class, [
            'scores' => [['expert' => $this->experts[0]->promptId, 'score' => 5]],
            'reasoning' => 'Nur einer hat etwas geliefert.',
        ]);

        $this->judge();

        JudgeAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'Entwurf 0')
            && str_contains($prompt->prompt, 'Entwurf 1')
            && str_contains($prompt->prompt, $this->experts[0]->promptId));
    }
}
