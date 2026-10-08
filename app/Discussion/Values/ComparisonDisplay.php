<?php

namespace App\Discussion\Values;

/** How the statistics page shows a comparison. */
enum ComparisonDisplay: string
{
    case Table = 'table';
    case Bars = 'bars';
    case Trajectory = 'trajectory';
    case Pie = 'pie';

    public function label(): string
    {
        return __("comparisons.displays.{$this->value}");
    }

    /** Whether this display can show the metric at all; the table always can. */
    public function supports(Metric $metric): bool
    {
        return match ($this) {
            self::Trajectory, self::Pie => $metric->isShare(),
            default => true,
        };
    }

    /** Why the option is disabled for a metric it does not support. */
    public function unsupportedHint(): string
    {
        return __("comparisons.results.no_{$this->value}");
    }
}
