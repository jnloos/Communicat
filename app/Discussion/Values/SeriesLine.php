<?php

namespace App\Discussion\Values;

/** One line of a series: one value per run, plus the averaged curve if one was asked for. */
final readonly class SeriesLine
{
    /**
     * @param  string  $key  '' for a single line, 'agent:<name>' for the lines of `each`
     * @param  ?string  $name  the agent's name for a line of `each`
     * @param  list<ProjectValue>  $values
     * @param  list<TrajectoryMean>  $curve
     */
    public function __construct(
        public string $key,
        public array $values,
        public array $curve = [],
        public ?string $name = null,
    ) {}

    public function n(): int
    {
        return count($this->values);
    }

    public function mean(): float
    {
        return $this->n() === 0 ? 0.0 : array_sum($this->numbers()) / $this->n();
    }

    public function min(): float
    {
        return $this->n() === 0 ? 0.0 : min($this->numbers());
    }

    public function max(): float
    {
        return $this->n() === 0 ? 0.0 : max($this->numbers());
    }

    /** @return list<float> */
    private function numbers(): array
    {
        return array_map(fn (ProjectValue $value) => $value->value, $this->values);
    }
}
