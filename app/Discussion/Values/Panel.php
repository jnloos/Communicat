<?php

namespace App\Discussion\Values;

/** One panel of an answer: a value of the split dimension, or the whole answer when nothing is split. */
final readonly class Panel
{
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
    ) {}

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
