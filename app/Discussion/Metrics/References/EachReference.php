<?php

namespace App\Discussion\Metrics\References;

use App\Discussion\Values\AgentGroup;
use App\Discussion\Values\Metric;
use App\Discussion\Values\ShareReport;
use App\Models\Project;

/**
 * Every agent on its own, one line each, named by the agent. Lines are keyed
 * by name, as "One expert by name" matches, so an agent is followed across
 * runs whatever seat they sit on and whichever record carries the name.
 */
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
        $idsByName = [];

        foreach ($report->speakers as $speaker) {
            $idsByName[$speaker->name][] = $speaker->expertId;
        }

        return array_map(
            fn (int|string $name) => new AgentGroup("agent:{$name}", $idsByName[$name], (string) $name),
            array_keys($idsByName),
        );
    }
}
