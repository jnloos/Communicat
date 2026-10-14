<?php

namespace Tests\Feature\Statistics;

use App\Discussion\Values\GroupResult;
use App\Discussion\Values\Panel;
use App\Discussion\Values\QuestionResult;
use App\Discussion\Values\RunValue;
use App\Livewire\Statistics\PanelGrid;
use Tests\TestCase;

class PanelGridTest extends TestCase
{
    public function test_a_row_per_first_value_a_cell_per_column_and_a_gap_where_no_run_is(): void
    {
        $group = new GroupResult('', '', [new RunValue(1, 'One', 0.5, null)]);
        $a1 = new Panel('a|1', 'A · one', [$group], 1, 1, 'a', 'A', '1', 'one');
        $b2 = new Panel('b|2', 'B · two', [$group], 1, 1, 'b', 'B', '2', 'two');
        $result = new QuestionResult([$a1, $b2], ['' => ''], 2, 0, columns: [1 => 'one', 2 => 'two']);

        $rows = PanelGrid::rows($result);

        $this->assertSame(['a', 'b'], array_column($rows, 'key'));
        $this->assertSame(['A', 'B'], array_column($rows, 'label'));
        $this->assertSame([$a1, null], $rows[0]['cells']);
        $this->assertSame([null, $b2], $rows[1]['cells']);
    }
}
