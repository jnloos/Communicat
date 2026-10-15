<?php

namespace App\Discussion\Values;

/**
 * Where one run lands in an answer, judged over the whole run: its panel and
 * which of its agents form which group. Neither depends on the turn window.
 */
final readonly class RunPlacement
{
    /** The panel's key: the run's split values joined, '' without a split. */
    public string $panelKey;

    /**
     * @param  list<int|string>  $parts  the run's value in each split dimension, in their order
     * @param  array<int|string, list<int>>  $groups  group key => the expert ids of that group in this run
     */
    public function __construct(
        public array $parts,
        public array $groups,
    ) {
        $this->panelKey = implode(Panel::KEY_SEPARATOR, $parts);
    }
}
