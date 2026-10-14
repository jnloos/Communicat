<?php

namespace App\Livewire\Statistics;

use App\Discussion\Values\Panel;
use App\Discussion\Values\QuestionResult;

/**
 * The panels of an answer split by two dimensions, as a grid: a row per value
 * of the first, a cell per value of the second, null where no run has that
 * combination, so every column lines up. Rows and columns keep their
 * dimensions' order.
 */
final class PanelGrid
{
    /** @return list<array{key: string, label: string, cells: list<?Panel>}> */
    public static function rows(QuestionResult $result): array
    {
        $empty = array_fill_keys(array_map('strval', array_keys($result->columns)), null);
        $rows = [];

        foreach ($result->panels as $panel) {
            $rows[$panel->row] ??= ['key' => $panel->row, 'label' => $panel->rowLabel, 'cells' => $empty];
            $rows[$panel->row]['cells'][$panel->column] = $panel;
        }

        return array_values(array_map(fn (array $row) => [...$row, 'cells' => array_values($row['cells'])], $rows));
    }
}
