<?php

namespace App\Discussion\Memory;

/**
 * What one agent gets to see, after Nonomura et al. (2025): shared History,
 * private Short-Term thought, shared Long-Term summary.
 */
final readonly class MemoryView
{
    /**
     * @param  list<array{token: ?string, name: string, content: string}>  $history
     */
    public function __construct(
        public array $history,
        public string $shortTerm,
        public string $longTerm,
    ) {}
}
