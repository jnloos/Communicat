<?php

namespace App\Discussion\Values;

/**
 * How a group of agents counts in a share. Together adds their shares, so the
 * groups of a run add up to 100 %; per head divides by the group's size, so a
 * group of one compares fairly with a group of three.
 */
enum Aggregate: string
{
    case Together = 'together';
    case PerHead = 'per_head';

    public function label(): string
    {
        return __("questions.aggregates.{$this->value}");
    }
}
