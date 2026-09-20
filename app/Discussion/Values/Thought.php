<?php

namespace App\Discussion\Values;

/** One agent's private thought for this turn, plus whatever its Think stage added. */
final readonly class Thought
{
    public function __construct(
        public int $expertId,
        public string $text,
        public ?int $priority = null,
        public ?string $proposal = null,
    ) {}
}
