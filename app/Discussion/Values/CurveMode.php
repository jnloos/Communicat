<?php

namespace App\Discussion\Values;

/**
 * How a curve over the run counts: everything since the window's first turn,
 * or only the last turns of the rolling window (config discussion.share_window).
 */
enum CurveMode: string implements HasDefault
{
    case Cumulative = 'cumulative';
    case Rolling = 'rolling';

    public static function default(): static
    {
        return self::Cumulative;
    }

    public function label(): string
    {
        return __("questions.curves.{$this->value}");
    }
}
