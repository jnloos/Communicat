<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Values\TurnRange;
use PHPUnit\Framework\TestCase;

class TurnRangeTest extends TestCase
{
    public function test_the_default_is_the_whole_run(): void
    {
        $range = new TurnRange;

        $this->assertSame(1, $range->from);
        $this->assertNull($range->to);
        $this->assertTrue($range->isWholeRun());
    }

    public function test_form_input_is_normalised(): void
    {
        $this->assertEquals(new TurnRange(3, 8), TurnRange::fromArray(['from' => '3', 'to' => '8']));
        $this->assertEquals(new TurnRange, TurnRange::fromArray(['from' => '', 'to' => null]));
        $this->assertEquals(new TurnRange, TurnRange::fromArray(['from' => 'x', 'to' => 0]));
        $this->assertEquals(new TurnRange, TurnRange::fromArray(['from' => -4]));
        $this->assertEquals(new TurnRange, TurnRange::fromArray('nonsense'));
    }

    public function test_an_upper_bound_below_the_lower_one_becomes_the_lower_one(): void
    {
        $this->assertEquals(new TurnRange(5, 5), TurnRange::fromArray(['from' => 5, 'to' => 2]));
    }

    public function test_an_empty_field_stays_empty_when_stored(): void
    {
        $this->assertSame(['from' => null, 'to' => null], (new TurnRange)->toArray());
        $this->assertSame(['from' => 4, 'to' => 9], (new TurnRange(4, 9))->toArray());
        $this->assertSame(['from' => null, 'to' => 9], (new TurnRange(1, 9))->toArray());
    }

    public function test_capping_only_moves_the_upper_bound_down(): void
    {
        $this->assertEquals(new TurnRange(1, 3), (new TurnRange)->cappedAt(3));
        $this->assertEquals(new TurnRange(1, 3), (new TurnRange(1, 10))->cappedAt(3));
        $this->assertEquals(new TurnRange(2, 2), (new TurnRange(2, 2))->cappedAt(3));
    }

    public function test_a_lower_bound_beyond_the_cap_is_kept_and_the_window_is_empty(): void
    {
        $this->assertEquals(new TurnRange(4, 3), (new TurnRange(4))->cappedAt(3));
    }

    public function test_only_the_default_is_the_whole_run(): void
    {
        $this->assertFalse((new TurnRange(2))->isWholeRun());
        $this->assertFalse((new TurnRange(1, 5))->isWholeRun());
    }
}
