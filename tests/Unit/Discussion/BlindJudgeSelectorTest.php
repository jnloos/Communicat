<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Agents\JudgeAgent;
use App\Discussion\Schemas\DraftChoiceSchema;
use App\Discussion\Selectors\BlindJudgeSelector;
use App\Discussion\TurnPayload;
use App\Discussion\Values\Selection;
use App\Discussion\Values\Thought;
use App\Models\Expert;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeAgents;
use Tests\TestCase;

class BlindJudgeSelectorTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    /** @var list<Expert> */
    private array $experts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create(['seed' => 4711]);
        $this->experts = Expert::factory()->count(4)->create()->all();

        foreach ($this->experts as $expert) {
            $this->project->addContributingExpert($expert);
        }
    }

    /** @param  list<int>|null  $drafting  indexes of the experts who handed in a draft; null means all */
    private function judge(?array $drafting = null, int $turnIndex = 1): Selection
    {
        $payload = new TurnPayload($this->project, $turnIndex);

        foreach ($this->experts as $index => $expert) {
            $drafted = $drafting === null || in_array($index, $drafting, true);
            $payload->addThought(new Thought($expert->id, 'Gedanke', proposal: $drafted ? "Entwurf {$index}" : null));
        }

        return app(BlindJudgeSelector::class)->select($payload);
    }

    /** @return array<string, int> label → expert id, as the selector presented the drafts */
    private function orderFor(int $turnIndex = 1): array
    {
        FakeAgents::always(JudgeAgent::class, ['label' => 'A', 'reasoning' => '-']);

        return $this->judge(turnIndex: $turnIndex)->signals['order'];
    }

    private function expertById(int $id): Expert
    {
        return collect($this->experts)->firstWhere('id', $id);
    }

    public function test_the_chosen_label_maps_back_to_its_author(): void
    {
        $order = $this->orderFor();
        $authorOfC = $order['C'];

        FakeAgents::always(JudgeAgent::class, ['label' => 'C', 'reasoning' => 'C ist am konkretesten.']);

        $selection = $this->judge();

        $this->assertSame($authorOfC, $selection->speaker->id);
        $this->assertSame('C ist am konkretesten.', $selection->reasoning);
        $this->assertSame('BlindJudgeSelector', $selection->selector);
        $this->assertSame(['label' => 'C', 'order' => $order, 'fallback' => false], $selection->signals);
        $this->assertFalse($selection->tieBroken);
    }

    /** The label sits next to the right draft in the prompt, so the mapping is the one the judge read. */
    public function test_each_label_stands_before_its_authors_draft(): void
    {
        FakeAgents::always(JudgeAgent::class, ['label' => 'A', 'reasoning' => '-']);

        $order = $this->judge()->signals['order'];
        $index = array_search($this->expertById($order['B']), $this->experts);

        JudgeAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, "[B]\nEntwurf {$index}"));
    }

    public function test_the_judge_answers_from_a_closed_list_of_labels(): void
    {
        FakeAgents::always(JudgeAgent::class, ['label' => 'A', 'reasoning' => '-']);

        $this->judge(drafting: [0, 2, 3]);

        JudgeAgent::assertPrompted(fn ($prompt) => $prompt->agent->schema === DraftChoiceSchema::class
            && $prompt->agent->schemaArgs === [['A', 'B', 'C']]);
    }

    public function test_the_order_is_reproducible_from_seed_and_turn(): void
    {
        $this->assertSame($this->orderFor(turnIndex: 5), $this->orderFor(turnIndex: 5));
    }

    /**
     * Seat order is not label order: over several turns the drafts land on
     * different letters, so a position effect cannot ride along with a seat.
     */
    public function test_the_order_changes_between_turns(): void
    {
        $orders = collect(range(1, 8))->map(fn (int $turn) => $this->orderFor($turn))->unique();

        $this->assertGreaterThan(1, $orders->count());
    }

    public function test_the_shuffle_leaves_the_global_rng_alone(): void
    {
        mt_srand(99);
        $expected = mt_rand();

        mt_srand(99);
        $this->orderFor();

        $this->assertSame($expected, mt_rand());
    }

    public function test_an_unknown_label_falls_back_to_the_tie_breaker_among_the_drafters(): void
    {
        FakeAgents::always(JudgeAgent::class, ['label' => 'Z', 'reasoning' => 'Unlesbar.']);

        $selection = $this->judge(drafting: [0, 1]);

        $this->assertSame('Z', $selection->signals['label']);
        $this->assertTrue($selection->signals['fallback']);
        $this->assertTrue($selection->tieBroken);
        $this->assertContains($selection->speaker->id, [$this->experts[0]->id, $this->experts[1]->id]);
    }

    /** Without a single draft there is nothing to judge, so no call is made at all. */
    public function test_no_draft_at_all_skips_the_judge(): void
    {
        FakeAgents::always(JudgeAgent::class, ['label' => 'A', 'reasoning' => '-']);

        $selection = $this->judge(drafting: []);

        JudgeAgent::assertNeverPrompted();
        $this->assertSame(['label' => null, 'order' => [], 'fallback' => true], $selection->signals);
        $this->assertTrue($selection->tieBroken);
    }

    /**
     * Blind means: no name, no role, no token -- not in a participant list, not
     * in front of a draft, not in front of a history entry, not as an addressee.
     */
    public function test_the_prompt_names_nobody(): void
    {
        $this->experts[0]->update(['role' => 'Chief Executive Officer']);
        $message = $this->project->addMessage('Ein früherer Beitrag.', $this->experts[0]);
        $message->forceFill(['addressee_expert_id' => $this->experts[1]->id])->save();
        FakeAgents::always(JudgeAgent::class, ['label' => 'A', 'reasoning' => '-']);

        $this->judge();

        JudgeAgent::assertPrompted(function ($prompt) {
            foreach ($this->experts as $expert) {
                if (str_contains($prompt->prompt, $expert->name)
                    || str_contains($prompt->prompt, $expert->role)
                    || preg_match('/\bE'.$expert->id.'\b/', $prompt->prompt)) {
                    return false;
                }
            }

            return ! str_contains($prompt->prompt, 'PARTICIPANTS')
                && ! str_contains($prompt->prompt, '->')
                && str_contains($prompt->prompt, 'Ein früherer Beitrag.');
        });
    }

    /**
     * What a draft says about its author stays in: "As CEO I propose ..." is
     * the content pathway the blind cell keeps measurable.
     */
    public function test_a_draft_keeps_what_it_says_about_its_author(): void
    {
        FakeAgents::always(JudgeAgent::class, ['label' => 'A', 'reasoning' => '-']);
        $payload = new TurnPayload($this->project, 1);
        $payload->addThought(new Thought($this->experts[0]->id, 'Gedanke', proposal: 'Als CEO schlage ich vor ...'));

        app(BlindJudgeSelector::class)->select($payload);

        JudgeAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'Als CEO schlage ich vor ...'));
    }
}
