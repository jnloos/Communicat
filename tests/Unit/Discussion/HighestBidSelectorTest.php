<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Selectors\HighestBidSelector;
use App\Discussion\TurnPayload;
use App\Discussion\Values\Selection;
use App\Discussion\Values\Thought;
use App\Models\Expert;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HighestBidSelectorTest extends TestCase
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

    /** @param  array<int, ?int>  $bids  expert index → priority */
    private function select(array $bids): Selection
    {
        $payload = new TurnPayload($this->project, 1);

        foreach ($bids as $index => $priority) {
            $payload->addThought(new Thought($this->experts[$index]->id, 'Gedanke', priority: $priority));
        }

        return app(HighestBidSelector::class)->select($payload);
    }

    public function test_the_highest_bid_gets_the_floor(): void
    {
        $selection = $this->select([0 => 2, 1 => 5, 2 => 3]);

        $this->assertSame($this->experts[1]->id, $selection->speaker->id);
        $this->assertFalse($selection->tieBroken);
        $this->assertSame('HighestBidSelector', $selection->selector);
    }

    public function test_all_bids_are_recorded_in_the_signals(): void
    {
        $selection = $this->select([0 => 2, 1 => 5, 2 => 3]);

        $this->assertSame([
            $this->experts[0]->promptId => 2,
            $this->experts[1]->promptId => 5,
            $this->experts[2]->promptId => 3,
        ], $selection->signals['bids']);
        $this->assertSame([], $selection->signals['fallbacks']);
    }

    public function test_a_missing_bid_counts_as_the_lowest_and_is_recorded(): void
    {
        $selection = $this->select([0 => null, 1 => 2, 2 => 3]);

        $this->assertSame($this->experts[2]->id, $selection->speaker->id);
        $this->assertSame(1, $selection->signals['bids'][$this->experts[0]->promptId]);
        $this->assertSame([$this->experts[0]->promptId], $selection->signals['fallbacks']);
    }

    public function test_a_tie_goes_to_the_tie_breaker_and_is_flagged(): void
    {
        $selection = $this->select([0 => 5, 1 => 5, 2 => 1]);

        $this->assertTrue($selection->tieBroken);
        $this->assertContains($selection->speaker->id, [$this->experts[0]->id, $this->experts[1]->id]);
    }

    public function test_the_same_seed_and_turn_break_a_tie_the_same_way(): void
    {
        $first = $this->select([0 => 5, 1 => 5, 2 => 1])->speaker->id;

        $this->assertSame($first, $this->select([0 => 5, 1 => 5, 2 => 1])->speaker->id);
    }

    public function test_without_any_readable_bid_everyone_is_a_candidate(): void
    {
        $selection = $this->select([0 => null, 1 => null, 2 => null]);

        $this->assertTrue($selection->tieBroken);
        $this->assertCount(3, $selection->signals['fallbacks']);
    }

    public function test_an_expert_who_never_thought_still_bids_the_lowest(): void
    {
        // A stage that skipped someone must not make them unselectable for ever.
        $selection = $this->select([0 => 4]);

        $this->assertSame($this->experts[0]->id, $selection->speaker->id);
        $this->assertCount(2, $selection->signals['fallbacks']);
    }
}
