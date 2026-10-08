<?php

namespace App\Discussion\Values;

/** A series computed: its lines, how many runs matched the filter, and how many of those dropped out. */
final readonly class SeriesResult
{
    /** @param  list<SeriesLine>  $lines */
    public function __construct(
        public string $label,
        public array $lines,
        public int $projects,
        public int $excluded,
    ) {}
}
