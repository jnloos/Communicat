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

    /**
     * The text colour that stays readable on a group's colour (WCAG AA, 4.5:1).
     * White fails on every 500 shade here (1.98:1 on lime to 4.23:1 on violet);
     * near-black reaches 4.70:1 on violet and 5.4:1 or more on the rest.
     */
    public static function textOn(string $color): string
    {
        return 'text-zinc-950';
    }
}
