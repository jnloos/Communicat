<?php

namespace App\Discussion\Values;

/**
 * The agents of one run a series looks at. Most references yield one group
 * (key ''); `each` yields one per seat, or per agent where it has none. Several agents in a group count
 * together; an empty group means the run falls out of that line.
 */
final readonly class AgentGroup
{
    /** @param  list<int>  $expertIds */
    public function __construct(
        public string $key,
        public array $expertIds,
        public ?int $seat = null,
        /** Names a line where no seat does: an `each` agent who has left the project. */
        public ?string $name = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->expertIds === [];
    }
}
