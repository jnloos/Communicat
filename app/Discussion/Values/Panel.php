<?php

namespace App\Discussion\Values;

/** One panel of an answer: a combination of the split dimensions' values, or the whole answer when nothing is split. */
final readonly class Panel
{
    /**
     * Joins a panel's value keys, one per split dimension: "QualityPipeline|10".
     * Assumes no value key contains it: pipeline class names, model keys and numeric ids cannot.
     */
    public const KEY_SEPARATOR = '|';

    /** Joins a panel's value labels: "Quality · seat 1". */
    public const LABEL_SEPARATOR = ' · ';

    /**
     * @param  list<GroupResult>  $groups  the baseline first, then in the order of the compare dimension
     * @param  int  $runs  runs that landed in this panel, whichever groups they had
     * @param  int  $compareCount  groups the whole answer compares, in all panels
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $groups,
        public int $runs = 0,
        public int $compareCount = 0,
        /** With two splits: the value key and label of the first dimension (the panel's row) and of the second (its column); '' otherwise. */
        public string $row = '',
        public string $rowLabel = '',
        public string $column = '',
        public string $columnLabel = '',
    ) {}

    /** The label to show: the panel of an answer that splits nothing has none and stands for all runs. */
    public function displayLabel(): string
    {
        return $this->label !== '' ? $this->label : __('questions.result.all');
    }

    /**
     * The other group's mean minus the baseline's (the baseline comes first).
     * Only when the whole answer compares exactly two groups, so every panel
     * offers it or none does.
     */
    public function delta(): ?float
    {
        return $this->compareCount === 2 && count($this->groups) === 2
            ? $this->groups[1]->mean() - $this->groups[0]->mean()
            : null;
    }

    /**
     * A group's share of the panel: its value summed over its runs, divided by
     * all runs of the panel. A group a run lacks counts as 0 there, so the
     * groups of a panel add up to the whole however unevenly they occur.
     */
    public function shareOf(GroupResult $group): float
    {
        return $this->runs === 0 ? 0.0 : array_sum(array_map(fn (RunValue $run) => $run->value, $group->runs)) / $this->runs;
    }
}
