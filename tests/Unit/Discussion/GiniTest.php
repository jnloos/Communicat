<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Metrics\Gini;
use PHPUnit\Framework\TestCase;

/**
 * The synthetic extreme cases the research plan asks the metrics to be
 * validated against, before any of them are read off a real run.
 */
class GiniTest extends TestCase
{
    public function test_a_perfectly_equal_group_scores_zero(): void
    {
        $this->assertSame(0.0, round(Gini::of([25, 25, 25, 25]), 10));
    }

    public function test_one_speaker_holding_everything_reaches_the_ceiling(): void
    {
        // Not 1.0: with n participants the maximum is (n-1)/n, here 0.75.
        $this->assertSame(0.75, round(Gini::of([100, 0, 0, 0]), 10));
        $this->assertSame(0.75, Gini::maximumFor(4));
    }

    public function test_it_matches_the_hand_computed_value_for_an_uneven_group(): void
    {
        // sorted 10,20,30,40: 2*(1*10+2*20+3*30+4*40)/(4*100) - 5/4 = 1.5 - 1.25
        $this->assertSame(0.25, round(Gini::of([40, 30, 20, 10]), 10));
    }

    public function test_order_does_not_matter(): void
    {
        $this->assertSame(
            round(Gini::of([10, 40, 20, 30]), 10),
            round(Gini::of([40, 30, 20, 10]), 10),
        );
    }

    public function test_a_run_with_no_turns_scores_zero_rather_than_dividing_by_zero(): void
    {
        $this->assertSame(0.0, Gini::of([0, 0, 0, 0]));
        $this->assertSame(0.0, Gini::of([]));
    }

    public function test_a_single_participant_has_no_inequality_to_report(): void
    {
        $this->assertSame(0.0, round(Gini::of([42]), 10));
        $this->assertSame(0.0, Gini::maximumFor(1));
        $this->assertSame(0.0, Gini::maximumFor(0));
    }

    public function test_the_ceiling_grows_with_the_group(): void
    {
        $this->assertSame(0.5, Gini::maximumFor(2));
        $this->assertSame(0.75, Gini::maximumFor(4));
        $this->assertSame(0.9, round(Gini::maximumFor(10), 10));
    }
}
