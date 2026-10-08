<?php

namespace App\Discussion\Values;

/** How the statistics page shows a comparison. */
enum ComparisonDisplay: string
{
    case Table = 'table';
    case Bars = 'bars';
    case Trajectory = 'trajectory';

    public function label(): string
    {
        return __("comparisons.displays.{$this->value}");
    }
}
