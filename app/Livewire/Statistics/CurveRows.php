<?php

namespace App\Livewire\Statistics;

use App\Discussion\Values\Panel;

/**
 * One panel's curves as flux:chart reads them: a row per turn, per group a
 * share in percent (g), the runs behind it (n) and, where the measure has
 * one, the group's fair share (f), the same on every row so it draws as a
 * flat dashed line.
 */
final class CurveRows
{
    /** @return list<array<string, int|float>> */
    public static function of(Panel $panel): array
    {
        $rows = [];

        foreach ($panel->groups as $index => $group) {
            $fairShare = $group->fairShare();

            foreach ($group->curve as $point) {
                $rows[$point->turn]['turn'] = $point->turn;
                $rows[$point->turn]["g{$index}"] = round($point->mean * 100, 1);
                $rows[$point->turn]["n{$index}"] = $point->n;

                if ($fairShare !== null) {
                    $rows[$point->turn]["f{$index}"] = round($fairShare * 100, 1);
                }
            }
        }

        ksort($rows);

        return array_values($rows);
    }
}
