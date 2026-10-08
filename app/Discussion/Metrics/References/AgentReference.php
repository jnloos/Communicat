<?php

namespace App\Discussion\Metrics\References;

use App\Discussion\Values\AgentGroup;
use App\Discussion\Values\Metric;
use App\Discussion\Values\ShareReport;
use App\Models\Project;

/**
 * Which agents of a run a series looks at. A new kind of reference is a new
 * class plus one line in AgentReferences::TYPES — never a condition elsewhere.
 */
interface AgentReference
{
    /** Builds the reference from its saved form; missing or broken parameters fall back to safe defaults. */
    public static function fromArray(array $data): static;

    /** The saved form, `type` included. */
    public function toArray(): array;

    /**
     * @param  ShareReport  $report  the run's shares, speakers in seat order
     * @param  Metric  $metric  the page's measure (only `top` reads it)
     * @return list<AgentGroup>
     */
    public function pick(Project $project, ShareReport $report, Metric $metric): array;
}
