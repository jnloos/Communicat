<?php

namespace App\Discussion\Values;

/**
 * What a run cost, per model and in total.
 *
 * Bookkeeping, not a study measure: the thesis counts words, never tokens, and
 * nothing in the discussion reads this. It exists so a run's price is known
 * before the next one is started, and so a budget can be planned from real
 * numbers instead of a guess.
 *
 * The total is an estimate, and knowingly an upper bound on input: the
 * providers charge less for a cached input token, and prompt_logs does not
 * record which tokens were cached.
 */
final readonly class CostReport
{
    /** @param  list<CostLine>  $lines  one per model that was actually called */
    public function __construct(public array $lines) {}

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    public function calls(): int
    {
        return (int) array_sum(array_map(fn (CostLine $line) => $line->calls, $this->lines));
    }

    public function tokensIn(): int
    {
        return (int) array_sum(array_map(fn (CostLine $line) => $line->tokensIn, $this->lines));
    }

    public function tokensOut(): int
    {
        return (int) array_sum(array_map(fn (CostLine $line) => $line->tokensOut, $this->lines));
    }

    /** The priced lines only; read it together with hasUnpricedModels(). */
    public function usd(): float
    {
        return array_sum(array_map(fn (CostLine $line) => $line->usd ?? 0.0, $this->lines));
    }

    /** True when at least one model has no configured price, so the total is short. */
    public function hasUnpricedModels(): bool
    {
        foreach ($this->lines as $line) {
            if ($line->usd === null) {
                return true;
            }
        }

        return false;
    }
}
