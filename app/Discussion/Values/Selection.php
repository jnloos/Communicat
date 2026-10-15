<?php

namespace App\Discussion\Values;

use App\Models\Expert;

/** Who got the floor, and everything the selector knew when deciding. */
final readonly class Selection
{
    /**
     * @param  string  $selector  short class name of the selector
     * @param  array  $signals  selector-specific data, built by SelectionRecord's *Signals() factories
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
        return SelectionRecord::written($this->selector, $this->speaker->id, $this->signals, $this->reasoning, $this->tieBroken)->toArray();
    }
}
