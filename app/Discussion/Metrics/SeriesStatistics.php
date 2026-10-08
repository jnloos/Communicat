<?php

namespace App\Discussion\Metrics;

use App\Discussion\Values\AgentGroup;
use App\Discussion\Values\ComparisonDisplay;
use App\Discussion\Values\ComparisonView;
use App\Discussion\Values\Metric;
use App\Discussion\Values\ProjectValue;
use App\Discussion\Values\SeriesDefinition;
use App\Discussion\Values\SeriesLine;
use App\Discussion\Values\SeriesResult;
use App\Discussion\Values\ShareReport;
use App\Discussion\Values\ShareSeries;
use App\Discussion\Values\SpeakerShare;
use App\Discussion\Values\Trajectory;
use App\Discussion\Values\TrajectoryMean;
use App\Models\Project;

/**
 * One series of a comparison, computed over its runs. Every number comes from
 * SpeakingShares and ShareTrajectory, so the counting rules stay in SpokenTurns;
 * this class only picks agents, combines them and averages across runs.
 *
 * - Several agents of a run count with their combined value.
 * - A run whose reference picks nobody, or that has no spoken turn, drops out
 *   of the series and is counted in `excluded`.
 * - A curve point is the mean over the runs that reached that turn, with n.
 */
class SeriesStatistics
{
    public function __construct(
        private readonly SpeakingShares $shares,
        private readonly ShareTrajectory $trajectories,
    ) {}

    /** @param  iterable<Project>  $projects  already filtered and access-checked (SeriesProjects) */
    public function compute(SeriesDefinition $series, ComparisonView $view, iterable $projects): SeriesResult
    {
        $metric = $view->metric;
        $withCurve = $view->display === ComparisonDisplay::Trajectory;
        $values = $seats = $names = $sums = $counts = $sizes = [];
        $matched = $excluded = 0;

        foreach ($projects as $project) {
            $matched++;
            $report = $this->shares->forProject($project, $view->turnLimit);
            $groups = $report->isEmpty() ? [] : $this->groupsFor($series, $project, $report, $metric);

            if ($groups === []) {
                $excluded++;

                continue;
            }

            $sizes[count($report->speakers)] = true;
            $trajectory = $withCurve ? $this->trajectories->forProject($project, null, $view->turnLimit) : null;

            foreach ($groups as $group) {
                $seats[$group->key] = $group->seat;
                $names[$group->key] = $group->name;
                $values[$group->key][] = new ProjectValue($project->title, $this->valueOf($metric, $report, $group));

                if ($trajectory !== null) {
                    $this->addCurve($sums, $counts, $group, $trajectory, $metric->series($view->trajectoryMode));
                }
            }
        }

        $lines = [];

        foreach ($values as $key => $projectValues) {
            $lines[] = new SeriesLine((string) $key, $seats[$key], $projectValues, $this->means($sums[$key] ?? [], $counts[$key] ?? []), $names[$key]);
        }

        usort($lines, fn (SeriesLine $a, SeriesLine $b) => [$a->seat ?? PHP_INT_MAX, $a->key] <=> [$b->seat ?? PHP_INT_MAX, $b->key]);

        $groupSizes = array_keys($sizes);
        sort($groupSizes);

        return new SeriesResult($series->label, $lines, $matched, $excluded, $groupSizes);
    }

    /**
     * The groups that count in this run. A run-level measure describes the
     * whole group and ignores the reference.
     *
     * @return list<AgentGroup>
     */
    private function groupsFor(SeriesDefinition $series, Project $project, ShareReport $report, Metric $metric): array
    {
        if ($metric->isRunLevel()) {
            return [new AgentGroup('', [])];
        }

        return array_values(array_filter(
            $series->reference->pick($project, $report, $metric),
            fn (AgentGroup $group) => ! $group->isEmpty() && $this->isDefined($metric, $report, $group),
        ));
    }

    private function valueOf(Metric $metric, ShareReport $report, AgentGroup $group): float
    {
        if ($metric->isRunLevel()) {
            return $metric->ofRun($report);
        }

        return $metric->ofAgents($this->speakersOf($report, $group));
    }

    /**
     * Words per turn of agents who never spoke is undefined, not 0: such a group
     * drops out like a reference that hits nobody. A share is a real 0 and stays.
     */
    private function isDefined(Metric $metric, ShareReport $report, AgentGroup $group): bool
    {
        return $metric !== Metric::WordsPerTurn
            || array_sum(array_map(fn (SpeakerShare $speaker) => $speaker->turns, $this->speakersOf($report, $group))) > 0;
    }

    /** @return list<SpeakerShare> */
    private function speakersOf(ShareReport $report, AgentGroup $group): array
    {
        return array_values(array_filter(
            $report->speakers,
            fn (SpeakerShare $speaker) => in_array($speaker->expertId, $group->expertIds, true),
        ));
    }

    /**
     * @param  array<string, array<int, float>>  $sums
     * @param  array<string, array<int, int>>  $counts
     */
    private function addCurve(array &$sums, array &$counts, AgentGroup $group, Trajectory $trajectory, ShareSeries $series): void
    {
        foreach ($trajectory->points as $point) {
            $share = 0.0;

            foreach ($group->expertIds as $expertId) {
                $share += $point->share($series, $expertId);
            }

            $sums[$group->key][$point->turn] = ($sums[$group->key][$point->turn] ?? 0.0) + $share;
            $counts[$group->key][$point->turn] = ($counts[$group->key][$point->turn] ?? 0) + 1;
        }
    }

    /**
     * @param  array<int, float>  $sums
     * @param  array<int, int>  $counts
     * @return list<TrajectoryMean>
     */
    private function means(array $sums, array $counts): array
    {
        ksort($sums);
        $points = [];

        foreach ($sums as $turn => $sum) {
            $points[] = new TrajectoryMean((int) $turn, $sum / $counts[$turn], $counts[$turn]);
        }

        return $points;
    }
}
