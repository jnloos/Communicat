<?php

namespace Tests\Feature\Discussion;

use App\Discussion\Agents\JudgeAgent;
use App\Discussion\Selectors\BlindJudgeSelector;
use App\Discussion\Support\PromptRenderer;
use App\Discussion\TurnPayload;
use App\Discussion\Values\MemoryView;
use App\Discussion\Values\Thought;
use App\Models\Expert;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\PromptFixtures;
use Tests\Fakes\FakeAgents;
use Tests\TestCase;

/**
 * What the team model's selectors read and how their letters fall must not move
 * while other things are built around them. The fixtures are the output of the
 * views before the Jev state partials were split out; unlike PromptGoldenTest's
 * six, these carry a non-empty memory, and the blind judge (which that test
 * does not pin) is here too.
 *
 * Regenerate a fixture only for a deliberate prompt change.
 */
class TeamSelectorGoldenTest extends TestCase
{
    use PromptFixtures, RefreshDatabase;

    private function memory(): MemoryView
    {
        return new MemoryView([
            ['position' => 'Proposal', 'name' => 'Alice Fixture', 'token' => 'E11', 'addressee' => ['name' => 'Bob Fixture', 'token' => 'E12'], 'content' => 'Phones distract.'],
            ['position' => null, 'name' => 'Bob Fixture', 'token' => 'E12', 'addressee' => null, 'content' => 'Not always.'],
        ], 'A private thought.', 'Earlier: the group weighed focus against safety.');
    }

    private function assertRendersFixture(string $fixture, string $view, array $data): void
    {
        $expected = File::get(base_path("tests/Fixtures/prompts/{$fixture}.txt"));

        $this->assertSame($expected, app(PromptRenderer::class)->render($view, $data), $fixture);
    }

    public function test_the_blind_judge_prompt_is_unchanged(): void
    {
        $data = ['project' => $this->fixtureProject(), 'memory' => $this->memory(), 'drafts' => ['B' => 'Second draft.', 'A' => 'First draft.']];

        $this->assertRendersFixture('select.blind-judge', 'prompts.select.blind-judge', $data);
    }

    public function test_the_judge_and_orchestrator_prompts_are_unchanged_with_a_memory(): void
    {
        $data = ['memory' => $this->memory(), 'drafts' => ['E11' => ['name' => 'Alice Fixture', 'draft' => 'First draft.'], 'E12' => ['name' => 'Bob Fixture', 'draft' => 'Second draft.']]]
            + $this->promptData($this->fixtureProject());

        $this->assertRendersFixture('select.judge.memory', 'prompts.select.judge', $data);
        $this->assertRendersFixture('select.orchestrator.memory', 'prompts.select.orchestrator', $data);
    }

    public function test_the_bidding_prompt_is_unchanged_with_a_memory(): void
    {
        $data = ['memory' => $this->memory()] + $this->promptData($this->fixtureProject());

        $this->assertRendersFixture('think.prioritize.memory', 'prompts.think.prioritize', $data);
    }

    /**
     * The letters the blind judge hands out for a fixed seed, turn and set of
     * drafters. A later refactor that lets BlindJudgeSelector share DraftLabels
     * with the Jev judge must leave every row of this table as it is.
     *
     * @return array<string, array{int, list<int>, array<string, int>}>
     */
    public static function letterAssignments(): array
    {
        return [
            'four drafters, turn 1' => [1, [21, 22, 23, 24], ['A' => 22, 'B' => 24, 'C' => 23, 'D' => 21]],
            'four drafters, turn 2' => [2, [21, 22, 23, 24], ['A' => 24, 'B' => 21, 'C' => 22, 'D' => 23]],
            'four drafters, turn 3' => [3, [21, 22, 23, 24], ['A' => 22, 'B' => 24, 'C' => 23, 'D' => 21]],
            'four drafters, turn 4' => [4, [21, 22, 23, 24], ['A' => 22, 'B' => 23, 'C' => 21, 'D' => 24]],
            'four drafters, turn 5' => [5, [21, 22, 23, 24], ['A' => 21, 'B' => 23, 'C' => 24, 'D' => 22]],
            'two drafters, turn 1' => [1, [22, 24], ['A' => 24, 'B' => 22]],
            'two drafters, turn 4' => [4, [22, 24], ['A' => 22, 'B' => 24]],
        ];
    }

    /**
     * @param  list<int>  $drafting  ids of the experts who handed in a draft
     * @param  array<string, int>  $order
     */
    #[DataProvider('letterAssignments')]
    public function test_the_blind_judge_assigns_the_same_letters_for_a_fixed_seed_and_turn(int $turn, array $drafting, array $order): void
    {
        $project = Project::factory()->create(['seed' => 20261019]);

        foreach ([21, 22, 23, 24] as $id) {
            $project->addContributingExpert(Expert::factory()->create(['id' => $id]));
        }

        FakeAgents::always(JudgeAgent::class, ['label' => 'A', 'reasoning' => '-']);
        $payload = new TurnPayload($project->fresh(), $turn);

        foreach ($project->contributingExperts() as $expert) {
            $payload->addThought(new Thought($expert->id, 'a thought', proposal: in_array($expert->id, $drafting, true) ? 'a draft' : null));
        }

        $selection = app(BlindJudgeSelector::class)->select($payload);

        $this->assertSame($order, $selection->signals['order']);
        $this->assertSame($order['A'], $selection->speaker->id, 'the judge answered "A"');
    }
}
