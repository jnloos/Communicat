<?php

namespace App\Discussion\Metrics\Questions;

use App\Discussion\Metrics\ShareTrajectory;
use App\Discussion\Metrics\SpeakingShares;
use App\Discussion\Values\Aggregate;
use App\Discussion\Values\Dimension;
use App\Discussion\Values\Metric;
use App\Discussion\Values\Panel;
use App\Discussion\Values\PeopleFilter;
use App\Discussion\Values\Question;
use App\Discussion\Values\QuestionResult;
use App\Discussion\Values\ResultForm;
use App\Discussion\Values\RunValue;
use App\Discussion\Values\ShareReport;
use App\Discussion\Values\SpeakerShare;
use App\Discussion\Values\Trajectory;
use App\Models\Expert;
use App\Models\Project;
use App\Models\User;

/**
 * Answers a question over the runs a user may read. Every number comes from
 * SpeakingShares and ShareTrajectory, so the counting rules stay in
 * SpokenTurns; this class only sorts runs into panels and agents into groups,
 * and averages.
 *
 * - A run without a spoken turn, without a value in a run dimension, or
 *   without anyone the people filter accepts, drops out and is counted.
 * - Panels and groups come in their dimension's order; one no run reached is
 *   left out.
 * - A curve point is the mean over the runs that reached that turn.
 */
final class QuestionEvaluator
{
    public function __construct(
        private readonly QuestionRuns $runs,
        private readonly DimensionValues $dimensions,
        private readonly SpeakingShares $shares,
        private readonly ShareTrajectory $trajectories,
    ) {}

    public function evaluate(User $user, Question $question): QuestionResult
    {
        /** @var array<int|string, array<int|string, GroupCollector>> $collected panel => group => collector */
        $collected = [];
        $runs = $excluded = 0;

        foreach ($this->runs->for($user, $question->projects) as $project) {
            $runs++;
            $panel = $question->splitBy === null ? '' : $this->dimensions->ofProject($question->splitBy, $project);
            $report = $this->shares->forProject($project);
            $groups = $panel === null || $report->isEmpty() ? [] : $this->groupsOf($question, $project, $report);

            if ($groups === []) {
                $excluded++;

                continue;
            }

            $trajectory = $question->form === ResultForm::Trajectory ? $this->trajectories->forProject($project) : null;

            foreach ($groups as $group => $speakers) {
                ($collected[$panel][$group] ??= new GroupCollector)->add(
                    $this->runValue($question, $project, $report, $speakers),
                    $trajectory === null ? [] : $this->curveOf($question, $trajectory, $speakers),
                );
            }
        }

        $groupLabels = $this->labels($question->compareBy, array_merge([], ...array_map('array_keys', array_values($collected))));

        return new QuestionResult(
            $this->panels($question, $collected, $groupLabels),
            array_combine(array_map('strval', array_keys($groupLabels)), $groupLabels),
            $runs,
            $excluded,
        );
    }

    /** @return array<int|string, non-empty-list<SpeakerShare>> group key => the group's agents in this run */
    private function groupsOf(Question $question, Project $project, ShareReport $report): array
    {
        $people = $question->metric->isRunLevel() ? $report->speakers : $this->people($question->people, $report->speakers);
        $compareBy = $question->compareBy;

        if ($compareBy === null) {
            $groups = ['' => $people];
        } elseif ($compareBy->isPersonaLevel()) {
            $keys = $this->dimensions->ofExperts($compareBy, array_map(fn (SpeakerShare $speaker) => $speaker->expertId, $people));
            $groups = [];

            foreach ($people as $speaker) {
                $groups[$keys[$speaker->expertId]][] = $speaker;
            }
        } else {
            $key = $this->dimensions->ofProject($compareBy, $project);
            $groups = $key === null ? [] : [$key => $people];
        }

        return array_filter($groups, fn (array $speakers) => $this->isMeasurable($question->metric, $speakers));
    }

    /**
     * @param  list<SpeakerShare>  $speakers
     * @return list<SpeakerShare>
     */
    private function people(PeopleFilter $filter, array $speakers): array
    {
        if ($filter->isEveryone()) {
            return $speakers;
        }

        $ids = Expert::whereKey(array_map(fn (SpeakerShare $speaker) => $speaker->expertId, $speakers))
            ->whereTagFilter($filter->tags)
            ->when($filter->expertName !== null, fn ($query) => $query->where('name', $filter->expertName))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_filter($speakers, fn (SpeakerShare $speaker) => in_array($speaker->expertId, $ids, true)));
    }

    /** No agents, or words per turn of agents who never spoke: no value, not a zero. */
    private function isMeasurable(Metric $metric, array $speakers): bool
    {
        return $speakers !== []
            && ($metric !== Metric::WordsPerTurn || array_sum(array_map(fn (SpeakerShare $speaker) => $speaker->turns, $speakers)) > 0);
    }

    /** @param  non-empty-list<SpeakerShare>  $speakers */
    private function runValue(Question $question, Project $project, ShareReport $report, array $speakers): RunValue
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
     * @param  non-empty-list<SpeakerShare>  $speakers
     * @return array<int, float> turn => the group's share after it
     */
    private function curveOf(Question $question, Trajectory $trajectory, array $speakers): array
    {
        $series = $question->metric->series($question->curve);
        $divisor = $question->aggregate === Aggregate::PerHead ? count($speakers) : 1;
        $curve = [];

        foreach ($trajectory->points as $point) {
            $share = 0.0;

            foreach ($speakers as $speaker) {
                $share += $point->share($series, $speaker->expertId);
            }

            $curve[$point->turn] = $share / $divisor;
        }

        return $curve;
    }

    /**
     * @param  array<int|string, array<int|string, GroupCollector>>  $collected
     * @param  array<int|string, string>  $groupLabels
     * @return list<Panel>
     */
    private function panels(Question $question, array $collected, array $groupLabels): array
    {
        $panels = [];

        foreach ($this->labels($question->splitBy, array_keys($collected)) as $panelKey => $panelLabel) {
            $groups = [];

            foreach ($groupLabels as $groupKey => $groupLabel) {
                if (isset($collected[$panelKey][$groupKey])) {
                    $groups[] = $collected[$panelKey][$groupKey]->result((string) $groupKey, $groupLabel);
                }
            }

            $panels[] = new Panel((string) $panelKey, $panelLabel, $groups);
        }

        return $panels;
    }

    /**
     * Labels for the keys that occur, in the dimension's own order. A key the
     * dimension no longer lists (a pipeline class since removed) keeps its key
     * as label rather than vanishing from the answer.
     *
     * @param  list<int|string>  $keys
     * @return array<int|string, string>
     */
    private function labels(?Dimension $dimension, array $keys): array
    {
        $present = array_flip($keys);
        $known = $dimension === null ? ['' => ''] : $this->dimensions->labels($dimension);
        $labels = array_intersect_key($known, $present);

        foreach (array_keys(array_diff_key($present, $labels)) as $key) {
            $labels[$key] = (string) $key;
        }

        return $labels;
    }
}
