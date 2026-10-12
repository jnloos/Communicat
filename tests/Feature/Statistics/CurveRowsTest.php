<?php

namespace Tests\Feature\Statistics;

use App\Discussion\Values\GroupResult;
use App\Discussion\Values\Panel;
use App\Discussion\Values\RunValue;
use App\Discussion\Values\TrajectoryMean;
use App\Livewire\Statistics\CurveRows;
use Tests\TestCase;

class CurveRowsTest extends TestCase
{
    private function group(string $key, array $curve): GroupResult
    {
        return new GroupResult($key, $key, [new RunValue(1, 'r', 0.5, null)], $curve);
    }

    public function test_groups_of_different_turn_ranges_share_rows_by_turn(): void
    {
        $panel = new Panel('', '', [
            $this->group('a', [new TrajectoryMean(1, 0.5, 3), new TrajectoryMean(2, 0.25, 3), new TrajectoryMean(3, 0.3333, 2)]),
            $this->group('b', [new TrajectoryMean(2, 0.75, 1), new TrajectoryMean(3, 0.5, 1)]),
        ]);

        $this->assertSame([
            ['turn' => 1, 'g0' => 50.0, 'n0' => 3],
            ['turn' => 2, 'g0' => 25.0, 'n0' => 3, 'g1' => 75.0, 'n1' => 1],
            ['turn' => 3, 'g0' => 33.3, 'n0' => 2, 'g1' => 50.0, 'n1' => 1],
        ], CurveRows::of($panel));
    }

    public function test_a_panel_without_curves_has_no_rows(): void
    {
        $this->assertSame([], CurveRows::of(new Panel('', '', [$this->group('a', [])])));
    }

    public function test_a_group_with_a_fair_share_carries_it_on_every_row_for_a_dashed_line(): void
    {
        $fair = new GroupResult('a', 'a', [new RunValue(1, 'r', 0.5, 0.25), new RunValue(2, 'r', 0.4, 0.25)], [new TrajectoryMean(1, 0.5, 2), new TrajectoryMean(2, 0.4, 2)]);

        $this->assertSame([
            ['turn' => 1, 'g0' => 50.0, 'n0' => 2, 'f0' => 25.0],
            ['turn' => 2, 'g0' => 40.0, 'n0' => 2, 'f0' => 25.0],
        ], CurveRows::of(new Panel('', '', [$fair])));
    }
}
