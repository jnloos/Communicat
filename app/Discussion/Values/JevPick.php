<?php

namespace App\Discussion\Values;

use App\Models\Expert;

/** Who Jev's choice (or, failing that, the tie breaker) gave the floor to, and what Jev said about it. */
final readonly class JevPick
{
    /**
     * @param  ?string  $key  the option the speaker stood under; null when the floor went to the fallback draw, or nothing was asked
     * @param  bool  $asked  Jev was sent a request; false when there was nothing to decide (one candidate, or no draft)
     * @param  bool  $fallback  Jev was asked and gave nothing readable, or nothing could be asked for lack of drafts: the floor was drawn
     * @param  array<string, float>  $probabilities  option => probability; empty when Jev gave none or was not asked
     */
    public function __construct(
        public Expert $speaker,
        public ?string $key,
        public bool $tieBroken,
        public bool $fallback,
        public bool $asked,
        public array $probabilities = [],
        public ?float $confidence = null,
        public ?string $model = null,
        public ?int $latencyMs = null,
    ) {}

    /** The Jev request failed or was unreadable: counts towards an outage. Not asking is not failing. */
    public function fellBack(): bool
    {
        return $this->asked && $this->fallback;
    }

    /** @param  array<string, mixed>  $signals  built by SelectionRecord's jev*PickSignals() */
    public function toSelection(string $selector, array $signals): Selection
    {
        return new Selection(
            speaker: $this->speaker,
            selector: $selector,
            signals: $signals,
            tieBroken: $this->tieBroken,
        );
    }
}
