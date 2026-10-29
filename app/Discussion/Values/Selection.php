<?php

namespace App\Discussion\Values;

use App\Models\Expert;
use LogicException;

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

    /**
     * The same selection with signals a stage before the selector left in the
     * payload (TurnPayload::addSelectionSignals); with nothing to add, the
     * selection itself. A key both sides wrote is a bug, not a precedence rule:
     * one of the two would silently lose measurement data.
     *
     * @param  array<string, mixed>  $extra
     *
     * @throws LogicException
     */
    public function withSignals(array $extra): self
    {
        if ($extra === []) {
            return $this;
        }

        $clash = array_keys(array_intersect_key($this->signals, $extra));

        if ($clash !== []) {
            throw new LogicException(sprintf('%s and an earlier stage both wrote the selection signal(s) [%s]; give one of them another name.', $this->selector, implode(', ', $clash)));
        }

        return new self(
            speaker: $this->speaker,
            selector: $this->selector,
            signals: $this->signals + $extra,
            reasoning: $this->reasoning,
            tieBroken: $this->tieBroken,
        );
    }

    /** Stored in job_logs.selection. */
    public function toArray(): array
    {
        return SelectionRecord::written($this->selector, $this->speaker->id, $this->signals, $this->reasoning, $this->tieBroken)->toArray();
    }
}
