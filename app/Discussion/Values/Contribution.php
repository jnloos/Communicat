<?php

namespace App\Discussion\Values;

use App\Llm\LlmResponse;

/** The public contribution of the turn; $text is what gets counted. */
final readonly class Contribution
{
    public function __construct(
        public string $text,
        public ?string $partnerToken,
        public ?string $pairType,
        public LlmResponse $response,
    ) {}
}
