<?php

namespace App\Livewire\Statistics;

use App\Discussion\Values\SeriesDefinition;

/**
 * The default names of series: a label may be stored empty, and the result
 * then shows "Series N", N one above the highest default number in use, so no
 * legend, row or tooltip renders blank and a removal never leads to two series
 * of the same name.
 */
final class SeriesLabels
{
    /**
     * @param  list<SeriesDefinition>  $definitions
     * @return list<SeriesDefinition> the same series, every empty label filled in
     */
    public static function withDefaults(array $definitions): array
    {
        $labelled = [];

        foreach ($definitions as $definition) {
            $labelled[] = $definition->label === ''
                ? $definition->withLabel(self::next([...$definitions, ...$labelled]))
                : $definition;
        }

        return $labelled;
    }

    /** @param  list<SeriesDefinition>  $definitions */
    public static function next(array $definitions): string
    {
        $placeholder = '__N__';
        $pattern = '/^'.str_replace(preg_quote($placeholder, '/'), '(\d+)', preg_quote(__('comparisons.series.default_label', ['n' => $placeholder]), '/')).'$/u';
        $highest = 0;

        foreach ($definitions as $definition) {
            if (preg_match($pattern, $definition->label, $match) === 1) {
                $highest = max($highest, (int) $match[1]);
            }
        }

        return __('comparisons.series.default_label', ['n' => $highest + 1]);
    }
}
