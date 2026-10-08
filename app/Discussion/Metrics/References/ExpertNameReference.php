<?php

namespace App\Discussion\Metrics\References;

use App\Discussion\Values\AgentGroup;
use App\Discussion\Values\Metric;
use App\Discussion\Values\ShareReport;
use App\Discussion\Values\SpeakerShare;
use App\Models\Project;

/** An expert by name — across every expert record that carries it. */
final class ExpertNameReference implements AgentReference
{
    public const TYPE = 'expert_name';

    public function __construct(public readonly string $name) {}

    public static function fromArray(array $data): static
    {
        return new self(trim((string) ($data['name'] ?? '')));
    }

    public function toArray(): array
    {
        return ['type' => self::TYPE, 'name' => $this->name];
    }

    public function pick(Project $project, ShareReport $report, Metric $metric): array
    {
        $ids = array_map(fn (SpeakerShare $s) => $s->expertId, array_filter($report->speakers, fn (SpeakerShare $s) => $this->name !== '' && $s->name === $this->name));

        return [new AgentGroup('', array_values($ids))];
    }
}
