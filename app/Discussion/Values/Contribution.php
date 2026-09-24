<?php

namespace App\Discussion\Values;

/** The public contribution of the turn; $text is what gets counted. */
final readonly class Contribution
{
    public function __construct(
        public string $text,
        public ?string $partnerToken,
        public ?string $pairType,
        public Completion $completion,
    ) {}
}
