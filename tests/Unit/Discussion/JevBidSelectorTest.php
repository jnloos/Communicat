<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Selectors\HighestBidSelector;
use App\Discussion\Selectors\JevBidSelector;
use App\Discussion\Support\TieBreaker;
use App\Discussion\TurnPayload;
use App\Discussion\Values\BidResolution;
use App\Discussion\Values\JevAgentBid;
use App\Discussion\Values\JevBid;
use App\Discussion\Values\SelectionRecord;
use App\Discussion\Values\Thought;
use App\Models\Expert;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JevBidSelectorTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    /** @var list<Expert> */
    private array $experts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create(['seed' => 99]);
        $this->experts = Expert::factory()->count(3)->create()->all();

        foreach ($this->experts as $expert) {
            $this->project->addContributingExpert($expert);
        }
    }

    /**
     * A payload as JevBids leaves it: the rounded bids in the thoughts, the unrounded scores in the signals.
     *
     * @param  list<?float>  $scores  one per expert, null for an unreadable Jev bid
     */
    private function payload(array $scores, int $turn = 1): TurnPayload
    {
        $payload = new TurnPayload($this->project->fresh(), $turn);
        $bids = [];

        foreach ($this->experts as $index => $expert) {
            $bid = new JevBid($scores[$index], null, null, 1);
            $payload->addThought((new Thought($expert->id, 'x', priority: 5))->withPriority($bid->level()));
            $bids[] = new JevAgentBid($expert->promptId, 5, $bid);
        }

        $payload->addSelectionSignals(SelectionRecord::jevBidSignals($bids, BidResolution::Unrounded));

        return $payload;
    }

    public function test_the_highest_unrounded_score_wins_where_the_rounded_bids_tie(): void
    {
        $payload = $this->payload([3.6, 3.7, 2.1]);

        $rounded = app(HighestBidSelector::class)->select($payload);
        $unrounded = app(JevBidSelector::class)->select($payload);

        $this->assertTrue($rounded->tieBroken, 'both round to 4');
        $this->assertSame($this->experts[1]->id, $unrounded->speaker->id);
        $this->assertFalse($unrounded->tieBroken);
        $this->assertSame(1, $unrounded->signals['jev_tie_pool']);
        $this->assertSame('JevBidSelector', $unrounded->selector);
        $this->assertSame([$this->experts[0]->promptId => 4, $this->experts[1]->promptId => 4, $this->experts[2]->promptId => 2], $unrounded->signals['bids'], 'the rounded bids stay what the export calls the speaker bid');
        $this->assertSame([], $unrounded->signals['fallbacks']);
    }

    public function test_only_exactly_equal_scores_tie_and_the_tie_breaker_draws_among_them(): void
    {
        $payload = $this->payload([4.25, 4.25, 1.5], turn: 6);

        $selection = app(JevBidSelector::class)->select($payload);
        $expected = app(TieBreaker::class)->pick([$this->experts[0], $this->experts[1]], $this->project, 6);

        $this->assertTrue($selection->tieBroken);
        $this->assertSame(2, $selection->signals['jev_tie_pool']);
        $this->assertSame($expected->id, $selection->speaker->id);
    }

    public function test_an_unreadable_score_counts_as_the_lowest_and_is_listed_as_a_fallback_never_as_a_reason_to_exclude(): void
    {
        $payload = $this->payload([null, 1.0, null]);

        $selection = app(JevBidSelector::class)->select($payload);

        $this->assertSame([$this->experts[0]->promptId, $this->experts[2]->promptId], $selection->signals['fallbacks']);
        $this->assertSame(3, $selection->signals['jev_tie_pool'], 'an unreadable bid ties with a real 1.0: it was never excluded');
        $this->assertTrue($selection->tieBroken);
    }

    public function test_it_balances_nothing_the_same_agent_may_win_every_turn(): void
    {
        foreach ([1, 2, 3] as $turn) {
            $this->assertSame($this->experts[2]->id, app(JevBidSelector::class)->select($this->payload([1.2, 2.2, 4.9], $turn))->speaker->id);
        }
    }
}
