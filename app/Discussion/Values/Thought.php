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

    /** The same thought with another bid (the Jev bidder replaces the agent's own). */
    public function withPriority(?int $priority): self
    {
        return new self($this->expertId, $this->text, $priority, $this->proposal);
    }
}
