<?php

namespace App\Discussion\Values;

/**
 * A window of spoken turns inside a run: the from-th to the to-th, counted the
 * way SpokenTurns counts. `to` null is open, up to the run's last turn.
 */
final readonly class TurnRange
{
    public function __construct(
        public int $from = 1,
        public ?int $to = null,
    ) {}

    /** Form input in, a window out: blank or non-numeric fields fall back, an upper bound below the lower one becomes the lower one. */
    public static function fromArray(mixed $data): self
    {
        $data = is_array($data) ? $data : [];
        $from = self::positive($data['from'] ?? null) ?? 1;
        $to = self::positive($data['to'] ?? null);

        return new self($from, $to === null ? null : max($from, $to));
    }

    /**
     * A first turn of 1 is stored as null: the inputs bind null, so an empty
     * field has to stay empty rather than fill itself with a 1.
     *
     * @return array{from: ?int, to: ?int}
     */
    public function toArray(): array
    {
        return ['from' => $this->from === 1 ? null : $this->from, 'to' => $this->to];
    }

    /**
     * The upper bound can only move down to $max. A lower bound beyond it stays
     * and leaves the window empty, which is what the question asked for.
     */
    public function cappedAt(int $max): self
    {
        return new self($this->from, min($this->to ?? $max, $max));
    }

    public function isWholeRun(): bool
    {
        return $this->from === 1 && $this->to === null;
    }

    private static function positive(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value >= 1 ? (int) $value : null;
    }
}
