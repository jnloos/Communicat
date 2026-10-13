<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Values\TokenTotal;
use PHPUnit\Framework\TestCase;

class TokenTotalTest extends TestCase
{
    public function test_every_call_reported_gives_an_exact_sum(): void
    {
        $total = TokenTotal::of([1200, 800]);

        $this->assertSame(2000, $total->sum);
        $this->assertTrue($total->complete);
        $this->assertSame(2000, $total->exact());
    }

    public function test_a_missing_call_leaves_a_lower_bound_and_no_exact_sum(): void
    {
        $total = TokenTotal::of([64, null]);

        $this->assertSame(64, $total->sum);
        $this->assertFalse($total->complete);
        $this->assertNull($total->exact());
    }

    public function test_no_call_reported_or_no_call_at_all_is_absent_not_zero(): void
    {
        $this->assertNull(TokenTotal::of([null, null])->sum);
        $this->assertNull(TokenTotal::of([])->sum);
        $this->assertNull(TokenTotal::of([])->exact());
    }

    public function test_a_reported_zero_counts(): void
    {
        $this->assertSame(0, TokenTotal::of([0, 0])->exact());
    }
}
