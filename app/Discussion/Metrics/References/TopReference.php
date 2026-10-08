<?php

namespace App\Discussion\Metrics\References;

use App\Discussion\Values\AgentGroup;
use App\Discussion\Values\Metric;
use App\Discussion\Values\ShareReport;
use App\Models\Project;

/**
 * The agent with the highest value of the page's measure in this run. A tie
 * goes to the lower seat: deterministic, and no balancing in either direction.
 */
final class TopReference implements AgentReference
{
    public const TYPE = 'top';

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
        $best = null;
        $bestValue = -INF;

        foreach ($report->speakers as $speaker) {
            $value = $metric->ofAgents([$speaker]);

            if ($value > $bestValue) {
                $best = $speaker;
                $bestValue = $value;
            }
        }

        return [new AgentGroup('', $best === null ? [] : [$best->expertId])];
    }
}
