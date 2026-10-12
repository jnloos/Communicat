<?php

namespace App\Discussion\Metrics\Questions;

use App\Discussion\Metrics\ShareTrajectory;
use App\Discussion\Metrics\SpeakingShares;
use App\Discussion\Metrics\SpokenTurns;
use App\Discussion\Values\Aggregate;
use App\Discussion\Values\CurveMode;
use App\Discussion\Values\Dimension;
use App\Discussion\Values\Metric;
use App\Discussion\Values\Panel;
use App\Discussion\Values\QuestionDefinition;
use App\Discussion\Values\QuestionResult;
use App\Discussion\Values\ResultForm;
use App\Discussion\Values\RunValue;
use App\Discussion\Values\ShareReport;
use App\Discussion\Values\SpeakerShare;
use App\Discussion\Values\Trajectory;
use App\Discussion\Values\TurnRange;
use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Answers a question over the runs a user may read. Every number comes from
 * SpeakingShares and ShareTrajectory, so the counting rules stay in
 * SpokenTurns; this class only sorts runs into panels and agents into groups,
 * and averages.
 *
 * - A run without a spoken turn, without a value in a run dimension, or
 *   without anyone the people filter accepts, drops out and is counted.
 * - Panels and groups come in their dimension's order, the baseline group
 *   first; one no run reached is left out.
 * - Every run is read over the same turns: the asked window, its upper bound
 *   capped at the shortest run that contributes to the answer. A run that drops
 *   out (no value in a dimension, nobody the people filter accepts) does not
 *   shorten the window of the others.
 * - A curve point is the mean over the runs that reached that turn. Every run
 *   counted has at least as many turns as the window's upper bound, so n is the
 *   same at each point, unless the window starts beyond the shortest run.
 *
 * Each run's panel and groups are worked out once, over the whole run; only
 * the report over the window is read a second time.
 */
final class QuestionEvaluator
{
    public function __construct(
        private readonly QuestionRuns $runs,
        private readonly DimensionValues $dimensions,
        private readonly SpeakingShares $shares,
        private readonly ShareTrajectory $trajectories,
        private readonly SpokenTurns $spoken,
    ) {}

    public function evaluate(User $user, QuestionDefinition $question): QuestionResult
    {
        $projects = $this->runs->for($user, $question->projects);
        // Groups belong to the viewer, and the tags of all runs are read ahead, so this copy serves this answer only.
        $values = $this->dimensions->forUser($user)->withRuns($projects);
        $accepted = $this->acceptedPeople($question, $projects);
        $places = $projects->mapWithKeys(fn (Project $project) => [$project->id => $this->placeOf($question, $project, $values, $accepted)]);

        $shortest = $this->shortestRun($projects->filter(fn (Project $project) => $places[$project->id] !== null));
        $turns = $this->window($question->turns, $shortest);

        // A start past the shortest run: the capped bound cannot make the window empty for the longer
        // runs (it may not fall below the start), so no run is read and all are counted as excluded.
        $beyondShortestRun = $shortest !== null && $question->turns->from > $shortest;

        /** @var array<int|string, array<int|string, GroupCollector>> $collected panel => group => collector */
        $collected = [];
        /** @var array<int|string, int> $panelRuns panel => runs that counted in it */
        $panelRuns = [];
        $excluded = 0;

        foreach ($projects as $project) {
            $place = $beyondShortestRun ? null : $places[$project->id];
            $read = $place === null ? [] : $this->collectRun($question, $project, $place['groups'], $turns);

            if ($read === []) {
                $excluded++;

                continue;
            }

            $panelRuns[$place['panel']] = ($panelRuns[$place['panel']] ?? 0) + 1;

            foreach ($read as $group => [$runValue, $curve]) {
                ($collected[$place['panel']][$group] ??= new GroupCollector)->add($runValue, $curve);
            }
        }

        $groupKeys = array_merge([], ...array_map('array_keys', array_values($collected)));
        [$groupLabels, $baseline] = $this->baselineFirst($this->labels($values, $question->compareBy, $groupKeys), $question);

        return new QuestionResult(
            $this->panels($values, $question, $collected, $groupLabels, $panelRuns),
            array_combine(array_map('strval', array_keys($groupLabels)), $groupLabels),
            $projects->count(),
            $excluded,
            $turns,
            $shortest !== null && ($question->turns->to === null || $question->turns->to > $shortest),
            $shortest,
            $baseline,
        );
    }

    /**
     * The asked window, its upper bound capped at the shortest run read; as
     * asked when no run spoke.
     */
    private function window(TurnRange $asked, ?int $shortest): TurnRange
    {
        return $shortest === null ? $asked : $asked->cappedAt($shortest);
    }

    /**
     * The experts the people filter accepts among everyone seated in these
     * runs, in one query; null when it accepts everyone (or the metric
     * describes whole runs and ignores it).
     *
     * @param  Collection<int, Project>  $projects
     * @return array<int, true>|null expert id => true
     */
    private function acceptedPeople(QuestionDefinition $question, Collection $projects): ?array
    {
        $filter = $question->people;

        if ($question->metric->isRunLevel() || $filter->isEveryone()) {
            return null;
        }

        $seated = DB::table('project_contributors')
            ->select('contributor_id')
            ->where('contributor_type', (new Expert)->getMorphClass())
            ->whereIn('project_id', $projects->modelKeys());

        return Expert::whereIn('id', $seated)
            ->whereTagFilter($filter->tags)
            ->when($filter->expertName !== null, fn ($query) => $query->where('name', $filter->expertName))
            ->pluck('id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }

    /**
     * Where a run lands in the answer, judged over the whole run: its panel and
     * which agents form which group. Neither depends on the window, so the
     * window can be capped by the runs that take part before it is applied to
     * any of them. Null when the run takes no part.
     *
     * @param  array<int, true>|null  $accepted
     * @return array{panel: int|string, groups: array<int|string, list<int>>}|null groups: key => expert ids
     */
    private function placeOf(QuestionDefinition $question, Project $project, DimensionValues $values, ?array $accepted): ?array
    {
        $panel = $question->splitBy === null ? '' : $values->ofProject($question->splitBy, $project);
        $report = $panel === null ? null : $this->shares->forProject($project);

        if ($report === null || $report->isEmpty()) {
            return null;
        }

        $people = $accepted === null
            ? $report->speakers
            : array_values(array_filter($report->speakers, fn (SpeakerShare $speaker) => isset($accepted[$speaker->expertId])));
        $groups = array_filter($this->grouped($question->compareBy, $project, $values, $people), fn (array $speakers) => $this->isMeasurable($question->metric, $speakers));

        return $groups === [] ? null : [
            'panel' => $panel,
            'groups' => array_map(fn (array $speakers) => array_map(fn (SpeakerShare $speaker) => $speaker->expertId, $speakers), $groups),
        ];
    }

    /**
     * @param  list<SpeakerShare>  $people
     * @return array<int|string, list<SpeakerShare>> group key => the group's agents in this run
     */
    private function grouped(?Dimension $compareBy, Project $project, DimensionValues $values, array $people): array
    {
        if ($compareBy === null) {
            return ['' => $people];
        }

        if (! $compareBy->isPersonaLevel()) {
            $key = $values->ofProject($compareBy, $project);

            return $key === null ? [] : [$key => $people];
        }

        $keys = $values->ofExperts($compareBy, array_map(fn (SpeakerShare $speaker) => $speaker->expertId, $people));
        $groups = [];

        foreach ($people as $speaker) {
            $groups[$keys[$speaker->expertId]][] = $speaker;
        }

        return $groups;
    }

    /**
     * One run read over the window: per group its value and, for a curve, its
     * curve. Empty when no group of the run has a value in this window.
     *
     * @param  array<int|string, list<int>>  $groups  group key => expert ids
     * @return array<int|string, array{0: RunValue, 1: array<int, float>}>
     */
    private function collectRun(QuestionDefinition $question, Project $project, array $groups, TurnRange $turns): array
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

        foreach ($groups as $group => $expertIds) {
            $speakers = array_values(array_filter(array_map(fn (int $id) => $byExpert[$id] ?? null, $expertIds)));

            if (! $this->isMeasurable($question->metric, $speakers)) {
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

    /**
     * The length of the shortest of these runs: the most turns every one can
     * be read over. A run that never spoke has no length to cap by.
     *
     * @param  Collection<int, Project>  $projects
     * @return int|null null when no run spoke
     */
    private function shortestRun(Collection $projects): ?int
    {
        $lengths = array_filter($this->spoken->counts($projects), fn (int $length) => $length > 0);

        return $lengths === [] ? null : min($lengths);
    }

    /** No agents, or words per turn of agents who never spoke: no value, not a zero. */
    private function isMeasurable(Metric $metric, array $speakers): bool
    {
        return $speakers !== []
            && ($metric !== Metric::WordsPerTurn || array_sum(array_map(fn (SpeakerShare $speaker) => $speaker->turns, $speakers)) > 0);
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

    /**
     * The groups with the baseline moved to the front, and the baseline's key:
     * the one asked for when a group has it, else the first group in dimension
     * order. Null without a compare dimension or without any group.
     *
     * @param  array<int|string, string>  $labels
     * @return array{0: array<int|string, string>, 1: ?string}
     */
    private function baselineFirst(array $labels, QuestionDefinition $question): array
    {
        if ($question->compareBy === null || $labels === []) {
            return [$labels, null];
        }

        $baseline = $question->baseline !== null && array_key_exists($question->baseline, $labels)
            ? $question->baseline
            : array_key_first($labels);

        return [[$baseline => $labels[$baseline]] + $labels, (string) $baseline];
    }

    /**
     * @param  array<int|string, array<int|string, GroupCollector>>  $collected
     * @param  array<int|string, string>  $groupLabels
     * @param  array<int|string, int>  $panelRuns
     * @return list<Panel>
     */
    private function panels(DimensionValues $values, QuestionDefinition $question, array $collected, array $groupLabels, array $panelRuns): array
    {
        $panels = [];

        foreach ($this->labels($values, $question->splitBy, array_keys($collected)) as $panelKey => $panelLabel) {
            $groups = [];

            foreach ($groupLabels as $groupKey => $groupLabel) {
                if (isset($collected[$panelKey][$groupKey])) {
                    $groups[] = $collected[$panelKey][$groupKey]->result((string) $groupKey, $groupLabel);
                }
            }

            $panels[] = new Panel((string) $panelKey, $panelLabel, $groups, $panelRuns[$panelKey], count($groupLabels));
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
    private function labels(DimensionValues $values, ?Dimension $dimension, array $keys): array
    {
        $present = array_flip($keys);
        $known = $dimension === null ? ['' => ''] : $values->labels($dimension);
        $labels = array_intersect_key($known, $present);

        foreach (array_keys(array_diff_key($present, $labels)) as $key) {
            $labels[$key] = (string) $key;
        }

        return $labels;
    }
}
