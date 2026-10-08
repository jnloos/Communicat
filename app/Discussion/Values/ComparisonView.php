<?php

namespace App\Discussion\Values;

/** The settings that apply to every series of a comparison. */
final readonly class ComparisonView
{
    public function __construct(
        public Metric $metric = Metric::TurnShare,
        public ComparisonDisplay $display = ComparisonDisplay::Table,
        /** Only the first N spoken turns of every run; null for whole runs. */
        public ?int $turnLimit = null,
        /** 'cumulative' or 'rolling', as ShareSeries names them. */
        public string $trajectoryMode = 'cumulative',
    ) {}

    public static function fromArray(array $data): self
    {
        $limit = $data['turn_limit'] ?? null;
        $mode = $data['trajectory_mode'] ?? null;

        return new self(
            metric: Metric::tryFrom((string) ($data['metric'] ?? '')) ?? Metric::TurnShare,
            display: ComparisonDisplay::tryFrom((string) ($data['display'] ?? '')) ?? ComparisonDisplay::Table,
            turnLimit: is_numeric($limit) && (int) $limit >= 1 ? (int) $limit : null,
            trajectoryMode: in_array($mode, ['cumulative', 'rolling'], true) ? $mode : 'cumulative',
        );
    }

    public function toArray(): array
    {
        return [
            'metric' => $this->metric->value,
            'display' => $this->display->value,
            'turn_limit' => $this->turnLimit,
            'trajectory_mode' => $this->trajectoryMode,
        ];
    }
}
