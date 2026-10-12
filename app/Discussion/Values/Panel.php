<?php

namespace App\Discussion\Values;

/** One panel of an answer: a value of the split dimension, or the whole answer when nothing is split. */
final readonly class Panel
{
    /** @param  list<GroupResult>  $groups  in the order of the compare dimension */
    public function __construct(
        public string $key,
        public string $label,
        public array $groups,
    ) {}

    /** The second group's mean minus the first's, when exactly two groups compare. */
    public function delta(): ?float
    {
        return count($this->groups) === 2 ? $this->groups[1]->mean() - $this->groups[0]->mean() : null;
    }
}
