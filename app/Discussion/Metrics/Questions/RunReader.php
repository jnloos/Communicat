<?php

namespace App\Discussion\Metrics\Questions;

use App\Discussion\Metrics\ShareTrajectory;
use App\Discussion\Metrics\SpeakingShares;
use App\Discussion\Values\Aggregate;
use App\Discussion\Values\CurveMode;
use App\Discussion\Values\QuestionDefinition;
use App\Discussion\Values\ResultForm;
use App\Discussion\Values\RunPlacement;
use App\Discussion\Values\RunValue;
use App\Discussion\Values\ShareReport;
use App\Discussion\Values\SpeakerShare;
use App\Discussion\Values\Trajectory;
use App\Discussion\Values\TurnRange;
use App\Models\Project;

/**
 * Reads one placed run over the answer's window: per group its value and, for
 * a curve, its curve. Every number comes from SpeakingShares and
 * ShareTrajectory, so the counting rules stay in SpokenTurns.
 */
final class RunReader
{
    public function __construct(
        private readonly SpeakingShares $shares,
        private readonly ShareTrajectory $trajectories,
    ) {}

    /**
     * Empty when no group of the run has a value in this window.
     *
     * @return array<int|string, array{0: RunValue, 1: array<int, float>}> group key => [its value, its curve (empty without one)]
     */
    public function read(QuestionDefinition $question, Project $project, RunPlacement $place, TurnRange $turns): array
    {
        $report = $this->shares->forProject($project, $turns);

        if ($report->isEmpty()) {
            return [];
        }

        $byExpert = [];

        foreach ($report->speakers as $speaker) {
            $byExpert[$speaker->expertId] = $speaker;
        }

        $read = [];
        $trajectory = null;

        foreach ($place->groups as $group => $expertIds) {
            $speakers = array_values(array_filter(array_map(fn (int $id) => $byExpert[$id] ?? null, $expertIds)));

            if (! $question->metric->hasValueFor($speakers)) {
                continue;
            }

            $trajectory ??= $this->trajectory($question, $project, $turns);
            $read[$group] = [
                $this->runValue($question, $project, $report, $speakers),
                $trajectory === null ? [] : $this->curveOf($question, $trajectory, $speakers, $turns->from),
            ];
        }

        return $read;
    }

    /** @param  non-empty-list<SpeakerShare>  $speakers */
    private function runValue(QuestionDefinition $question, Project $project, ShareReport $report, array $speakers): RunValue
    {
        $metric = $question->metric;

        return new RunValue(
            projectId: $project->id,
            title: $project->title,
            value: $metric->isRunLevel() ? $metric->ofRun($report) : $metric->ofGroup($speakers, $question->aggregate),
            fairShare: $metric->fairShare(count($speakers), count($report->speakers), $question->aggregate),
        );
    }

    /**
     * The run's curve, or null when the answer is not one. A rolling share at
     * the window's first turn looks back over the rolling window, so those
     * turns before it are read too; curveOf() drops their points. A cumulative
     * share counts from the window's first turn.
     */
    private function trajectory(QuestionDefinition $question, Project $project, TurnRange $turns): ?Trajectory
    {
        if ($question->form !== ResultForm::Trajectory) {
            return null;
        }

        $read = $question->curve === CurveMode::Rolling
            ? new TurnRange(max(1, $turns->from - (int) config('discussion.share_window') + 1), $turns->to)
            : $turns;

        return $this->trajectories->forProject($project, turns: $read);
    }

    /**
     * @param  non-empty-list<SpeakerShare>  $speakers
     * @return array<int, float> turn => the group's share after it, from the window's first turn on
     */
    private function curveOf(QuestionDefinition $question, Trajectory $trajectory, array $speakers, int $from): array
    {
        $series = $question->metric->series($question->curve);
        $divisor = $question->aggregate === Aggregate::PerHead ? count($speakers) : 1;
        $curve = [];

        foreach ($trajectory->points as $point) {
            if ($point->turn < $from) {
                continue;
            }

            $share = 0.0;

            foreach ($speakers as $speaker) {
                $share += $point->share($series, $speaker->expertId);
            }

            $curve[$point->turn] = $share / $divisor;
        }

        return $curve;
    }
}
