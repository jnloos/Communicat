<?php

namespace App\Discussion\Metrics\References;

use App\Discussion\Values\AgentGroup;
use App\Discussion\Values\Metric;
use App\Discussion\Values\ShareReport;
use App\Models\Project;

/** Every agent on its own: one line per seat. */
final class EachReference implements AgentReference
{
    public const TYPE = 'each';

    public static function fromArray(array $data): static
    {
        return new self;
    }

    public function toArray(): array
    {
        return ['type' => self::TYPE];
    }

    public function pick(Project $project, ShareReport $report, Metric $metric): array
    {
        $groups = [];

        foreach ($report->speakers as $speaker) {
            $key = $speaker->seat === null ? "expert-{$speaker->expertId}" : "seat-{$speaker->seat}";
            $groups[] = new AgentGroup($key, [$speaker->expertId], $speaker->seat);
        }

        return $groups;
    }
}
