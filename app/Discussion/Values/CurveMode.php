<?php

namespace App\Discussion\Values;

/**
 * How a curve over the run counts: everything since the window's first turn,
 * or only the last turns of the rolling window (config discussion.share_window).
 */
enum CurveMode: string
{
    case Cumulative = 'cumulative';
    case Rolling = 'rolling';

    public function label(): string
    {
        return __("questions.curves.{$this->value}");
    }
}
