<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Support\TieBreaker;
use App\Models\Expert;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TieBreakerTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<Expert> */
    private function experts(int $count): array
    {
        return Expert::factory()->count($count)->create()->all();
    }

    public function test_a_single_candidate_needs_no_draw(): void
    {
        $experts = $this->experts(1);
        $project = Project::factory()->create(['seed' => 12345]);

        $this->assertSame($experts[0]->id, app(TieBreaker::class)->pick($experts, $project, 1)->id);
    }

    public function test_the_same_seed_and_turn_always_pick_the_same_candidate(): void
    {
        $experts = $this->experts(4);
        $project = Project::factory()->create(['seed' => 12345]);
        $breaker = app(TieBreaker::class);

        $first = $breaker->pick($experts, $project, 7)->id;

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame($first, $breaker->pick($experts, $project, 7)->id);
        }
    }

    public function test_the_order_of_the_candidate_list_does_not_change_the_outcome(): void
    {
        $experts = $this->experts(4);
        $project = Project::factory()->create(['seed' => 999]);
        $breaker = app(TieBreaker::class);

        $shuffled = $experts;
        $shuffled = array_reverse($shuffled);

        $this->assertSame(
            $breaker->pick($experts, $project, 3)->id,
            $breaker->pick($shuffled, $project, 3)->id,
        );
    }

    public function test_a_different_seed_or_turn_can_pick_someone_else(): void
    {
        $experts = $this->experts(4);
        $breaker = app(TieBreaker::class);
        $project = Project::factory()->create(['seed' => 12345]);

        // Over many turns the draw must not stick to one candidate.
        $picked = [];
        for ($turn = 1; $turn <= 40; $turn++) {
            $picked[$breaker->pick($experts, $project, $turn)->id] = true;
        }

        $this->assertGreaterThan(1, count($picked), 'The draw always returns the same candidate.');
    }

    public function test_every_candidate_comes_up_over_enough_turns(): void
    {
        $experts = $this->experts(4);
        $project = Project::factory()->create(['seed' => 4711]);
        $breaker = app(TieBreaker::class);

        $picked = [];
        for ($turn = 1; $turn <= 400; $turn++) {
            $picked[$breaker->pick($experts, $project, $turn)->id] = true;
        }

        $this->assertCount(4, $picked, 'Some candidate is never drawn.');
    }
}
