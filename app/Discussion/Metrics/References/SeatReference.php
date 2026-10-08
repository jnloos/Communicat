<?php

namespace App\Discussion\Metrics\References;

use App\Discussion\Values\AgentGroup;
use App\Discussion\Values\Metric;
use App\Discussion\Values\ShareReport;
use App\Discussion\Values\SpeakerShare;
use App\Models\Project;

/** Whoever sits on one seat. */
final class SeatReference implements AgentReference
{
    public const TYPE = 'seat';

    public function __construct(public readonly int $seat) {}

    public static function fromArray(array $data): static
    {
        $seat = $data['seat'] ?? null;

        return new self(is_numeric($seat) && (int) $seat >= 1 ? (int) $seat : 1);
    }

    public function toArray(): array
    {
        return ['type' => self::TYPE, 'seat' => $this->seat];
    }

    public function pick(Project $project, ShareReport $report, Metric $metric): array
    {
        $ids = array_map(fn (SpeakerShare $s) => $s->expertId, array_filter($report->speakers, fn (SpeakerShare $s) => $s->seat === $this->seat));

        return [new AgentGroup('', array_values($ids))];
    }
}
