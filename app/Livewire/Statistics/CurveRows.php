<?php

namespace App\Livewire\Statistics;

use App\Discussion\Values\Panel;

/** One panel's curves as flux:chart reads them: a row per turn, per group a share in percent (g) and the runs behind it (n). */
final class CurveRows
{
    /** @return list<array<string, int|float>> */
    public static function of(Panel $panel): array
    {
        $rows = [];

        foreach ($panel->groups as $index => $group) {
            foreach ($group->curve as $point) {
                $rows[$point->turn]['turn'] = $point->turn;
                $rows[$point->turn]["g{$index}"] = round($point->mean * 100, 1);
                $rows[$point->turn]["n{$index}"] = $point->n;
            }
        }

        ksort($rows);

        return array_values($rows);
    }
}
