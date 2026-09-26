<?php

namespace App\Discussion\Values;

/** The public contribution of the turn; $text is what gets counted. */
final readonly class Contribution
{
    public function __construct(
        public string $text,
        public ?string $addresseeToken,
        public Completion $completion,
    ) {}
}
