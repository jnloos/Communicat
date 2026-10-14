<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Values\GroupResult;
use App\Discussion\Values\RunValue;
use PHPUnit\Framework\TestCase;

class GroupResultTest extends TestCase
{
    private function group(float ...$values): GroupResult
    {
        $runs = array_map(fn (float $value, int $i) => new RunValue($i + 1, "Run {$i}", $value, 0.25), $values, array_keys($values));

        return new GroupResult('g', 'Group', $runs);
    }

    public function test_the_standard_deviation_is_the_sample_one(): void
    {
        // Mean 0.5, squared deviations 0.0625 + 0 + 0.0625, divided by n − 1 = 2.
        $this->assertEqualsWithDelta(0.25, $this->group(0.25, 0.5, 0.75)->standardDeviation(), 1e-12);
    }

    public function test_identical_runs_have_no_spread(): void
    {
        $this->assertSame(0.0, $this->group(0.4, 0.4)->standardDeviation());
    }

    public function test_a_single_run_has_no_standard_deviation(): void
    {
        $this->assertNull($this->group(0.4)->standardDeviation());
    }
}
