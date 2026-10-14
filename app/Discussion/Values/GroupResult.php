<?php

namespace App\Discussion\Values;

/** One group of one panel: its value in every run that has it, and its mean curve. */
final readonly class GroupResult
{
    /**
     * @param  non-empty-list<RunValue>  $runs
     * @param  list<TrajectoryMean>  $curve  empty unless the answer is a curve
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $runs,
        public array $curve = [],
    ) {}

    public function n(): int
    {
        return count($this->runs);
    }

    public function mean(): float
    {
        return array_sum($this->values()) / $this->n();
    }

    /**
     * The sample standard deviation over the runs (n − 1, since the runs are a
     * sample of what the cell could produce); null for a single run, which has
     * no spread to show.
     */
    public function standardDeviation(): ?float
    {
        if ($this->n() < 2) {
            return null;
        }

        $mean = $this->mean();
        $squares = array_map(fn (float $value) => ($value - $mean) ** 2, $this->values());

        return sqrt(array_sum($squares) / ($this->n() - 1));
    }

    public function min(): float
    {
        return min($this->values());
    }

    public function max(): float
    {
        return max($this->values());
    }

    /** The mean fair share over the runs; null for a measure without one. */
    public function fairShare(): ?float
    {
        $shares = array_map(fn (RunValue $run) => $run->fairShare, $this->runs);

        return in_array(null, $shares, true) ? null : array_sum($shares) / $this->n();
    }

    /** @return list<float> */
    private function values(): array
    {
        return array_map(fn (RunValue $run) => $run->value, $this->runs);
    }
}
