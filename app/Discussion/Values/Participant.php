<?php

namespace App\Discussion\Values;

/** Someone seated in a run, as a curve needs them: who, and on which seat. */
final readonly class Participant
{
    public function __construct(
        public int $expertId,
        public string $name,
        public ?int $seat,
    ) {}
}
