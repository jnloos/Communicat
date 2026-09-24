<?php

namespace App\Discussion\Values;

/**
 * One model answer. Deliberately narrow: these objects cross a process boundary
 * when prompts run in parallel, so nothing here may hold a connection or a
 * response object. reasoningTokens stays nullable — not every provider reports it,
 * and null must not be confused with zero.
 */
final readonly class Completion
{
    public function __construct(
        public string $text,
        public string $reasoning,
        public int $inputTokens,
        public int $outputTokens,
        public ?int $reasoningTokens,
    ) {}
}
