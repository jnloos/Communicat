<?php

namespace App\Discussion;

final readonly class TurnResult
{
    private function __construct(
        public bool $stop,
        public ?string $reason,
    ) {}

    public static function proceed(): self
    {
        return new self(false, null);
    }

    public static function stop(string $reason): self
    {
        return new self(true, $reason);
    }
}
