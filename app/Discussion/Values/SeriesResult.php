<?php

namespace App\Discussion\Values;

/** A series computed: its lines, how many runs matched the filter, how many of those dropped out, and the group sizes of the rest. */
final readonly class SeriesResult
{
    /**
     * @param  list<SeriesLine>  $lines
     * @param  list<int>  $groupSizes  how many agents sat in the counted runs, each size once
     */
    public function __construct(
        public string $label,
        public array $lines,
        public int $projects,
        public int $excluded,
        public array $groupSizes = [],
    ) {}
}
