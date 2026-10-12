<?php

namespace App\Discussion\Values;

/** The answer to a question: its panels, the groups they compare, and how many runs it read. */
final readonly class QuestionResult
{
    /**
     * @param  list<Panel>  $panels
     * @param  array<string, string>  $groups  every group that occurs, key => label, in dimension order
     * @param  int  $runs  runs the project filter matched
     * @param  int  $excluded  of those, runs without a value
     */
    public function __construct(
        public array $panels,
        public array $groups,
        public int $runs,
        public int $excluded,
    ) {}

    public function isEmpty(): bool
    {
        return $this->panels === [];
    }

    /** The largest value of any run, so all panels of a non-share measure share one scale. */
    public function maxValue(): float
    {
        $max = 0.0;

        foreach ($this->panels as $panel) {
            foreach ($panel->groups as $group) {
                $max = max($max, $group->max());
            }
        }

        return $max;
    }

    /** A group's position among all groups: the same group keeps its colour in every panel. */
    public function groupIndex(string $key): int
    {
        return (int) array_search($key, array_map('strval', array_keys($this->groups)), true);
    }
}
