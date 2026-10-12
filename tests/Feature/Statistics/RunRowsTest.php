<?php

namespace Tests\Feature\Statistics;

use App\Discussion\Values\GroupResult;
use App\Discussion\Values\Panel;
use App\Discussion\Values\QuestionResult;
use App\Discussion\Values\RunValue;
use App\Livewire\Statistics\RunRows;
use Tests\TestCase;

class RunRowsTest extends TestCase
{
    public function test_one_row_per_run_with_a_value_per_group(): void
    {
        // Run 1 has both groups, run 2 only the second; run 3 sits in another panel.
        $result = new QuestionResult([
            new Panel('score', 'Score', [
                new GroupResult('7', 'Status: no', [new RunValue(1, 'One', 0.25, null)]),
                new GroupResult('6', 'Status: yes', [new RunValue(1, 'One', 0.5, null), new RunValue(2, 'Two', 0.75, null)]),
            ]),
            new Panel('rr', 'Round robin', [
                new GroupResult('6', 'Status: yes', [new RunValue(3, 'Three', 0.1, null)]),
            ]),
        ], ['7' => 'Status: no', '6' => 'Status: yes'], 3, 0);

        $rows = RunRows::of($result);

        $this->assertSame([1, 2, 3], array_map(fn ($row) => $row['projectId'], $rows));
        $this->assertSame(['One', 'Two', 'Three'], array_map(fn ($row) => $row['title'], $rows));
        $this->assertSame(['Score', 'Score', 'Round robin'], array_map(fn ($row) => $row['panel'], $rows));
        $this->assertSame(['7' => 0.25, '6' => 0.5], $rows[0]['values']);
        $this->assertSame(['6' => 0.75], $rows[1]['values']);
    }
}
