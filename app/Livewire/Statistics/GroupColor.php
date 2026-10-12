<?php

namespace App\Livewire\Statistics;

use App\Discussion\Values\GroupResult;
use App\Discussion\Values\QuestionResult;

/**
 * The colour of a group: by its place among all groups of the answer, so one
 * group keeps its colour in every panel, form and legend.
 */
final class GroupColor
{
    /** Tailwind colours with enough contrast to each other in both themes. */
    private const PALETTE = ['blue-500', 'amber-500', 'emerald-500', 'rose-500', 'violet-500', 'cyan-500', 'lime-500', 'fuchsia-500'];

    public static function of(QuestionResult $result, GroupResult $group): string
    {
        return self::at($result->groupIndex($group->key));
    }

    public static function at(int $index): string
    {
        return self::PALETTE[$index % count(self::PALETTE)];
    }
}
