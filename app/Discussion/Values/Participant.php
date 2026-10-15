<?php

namespace App\Discussion\Values;

use Illuminate\Contracts\Support\Arrayable;

/** Someone seated in a run, as a curve needs them: who, and on which seat. */
/** @implements Arrayable<string, int|string|null> */
final readonly class Participant implements Arrayable
{
    public function __construct(
        public int $expertId,
        public string $name,
        public ?int $seat,
    ) {}

    /** @return array{expert_id: int, name: string, seat: ?int} */
    public function toArray(): array
    {
        return ['expert_id' => $this->expertId, 'name' => $this->name, 'seat' => $this->seat];
    }
}
