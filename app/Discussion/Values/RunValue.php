<?php

namespace App\Discussion\Values;

/** One run's value for one group: a dot in the answer, a row in the run table. */
final readonly class RunValue
{
    public function __construct(
        public int $projectId,
        public string $title,
        public float $value,
        /** What the group would get if everyone spoke equally; null for a measure without one. */
        public ?float $fairShare,
    ) {}
}
