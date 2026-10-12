<?php

namespace App\Livewire\Statistics;

use App\Discussion\Values\QuestionResult;

/**
 * The run table under an answer: one row per run, in the order the panels
 * list them, with its panel and its value in every group it has. A run lands
 * in one panel only, so the rows count the runs the answer read.
 */
final class RunRows
{
    /** @return list<array{projectId: int, title: string, panel: string, values: array<string, float>}> values: group key => value */
    public static function of(QuestionResult $result): array
    {
        $rows = [];

        foreach ($result->panels as $panel) {
            foreach ($panel->groups as $group) {
                foreach ($group->runs as $run) {
                    $rows[$run->projectId] ??= ['projectId' => $run->projectId, 'title' => $run->title, 'panel' => $panel->label, 'values' => []];
                    $rows[$run->projectId]['values'][$group->key] = $run->value;
                }
            }
        }

        return array_values($rows);
    }
}
