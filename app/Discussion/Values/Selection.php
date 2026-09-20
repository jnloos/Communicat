<?php

namespace App\Discussion\Values;

use App\Models\Expert;

/** Who got the floor, and everything the selector knew when deciding. */
final readonly class Selection
{
    /**
     * @param  string  $selector  short class name of the selector
     * @param  array  $signals  selector-specific data per agent (bids, judge scores, seat)
     */
    public function __construct(
        public Expert $speaker,
        public string $selector,
        public array $signals = [],
        public string $reasoning = '',
        public bool $tieBroken = false,
    ) {}

    /** Stored in job_logs.selection. */
    public function toArray(): array
    {
        return [
            'selector' => $this->selector,
            'speaker_id' => $this->speaker->id,
            'signals' => $this->signals,
            'reasoning' => $this->reasoning,
            'tie_broken' => $this->tieBroken,
        ];
    }
}
