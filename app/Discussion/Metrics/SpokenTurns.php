<?php

namespace App\Discussion\Metrics;

use App\Discussion\Values\JobStatus;
use App\Discussion\Values\TurnRange;
use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use WeakMap;

/**
 * The study's counting rules, in one place: which job_logs rows are spoken
 * turns, and who the participants are.
 *
 * Every metric reads its turns from here — SpeakingShares for the totals,
 * ShareTrajectory for the curve — so the two cannot drift apart, and a turn
 * that counts in one counts in the other.
 *
 * The rules, and why they are what they are:
 *
 * - **A turn counts as spoken when `words` is not null, never when
 *   `status = 'success'`.** PersistMessage saves the public message before
 *   later stages run, so a turn can speak and then fail in Summarize. That turn
 *   produced a real contribution the participants saw; excluding it would
 *   silently drop data, and TurnRunner::budgetIsSpent() already counts this way.
 * - **Only public contributions count.** Reasoning is measured apart in
 *   `thought_words`, and selector and judge calls never write `words` at all.
 * - **Words, not model tokens.** The tokenizers of the model families differ,
 *   so tokens would bias the contrast between models the study exists to draw.
 * - **Every contributing expert appears, including one that never spoke.** A
 *   zero is a finding, not a missing row — and it is what pulls the Gini
 *   coefficient up. An expert who spoke and has since left the run appears
 *   too, after the seated ones: its turns are in the totals.
 */
class SpokenTurns
{
    /** @var WeakMap<Project, Collection<int, Expert>>|null */
    private ?WeakMap $leavers = null;

    /**
     * The project's spoken turns. Unordered: a caller that aggregates needs no
     * order, and one that walks the turns calls inOrder().
     *
     * @return Builder<JobLog>
     */
    public function of(Project $project): Builder
    {
        return $this->spoken()->where('project_id', $project->id);
    }

    /**
     * How many turns each run spoke, in one query. Runs without a spoken turn
     * are absent from the result, not zero.
     *
     * @param  iterable<Project>  $projects
     * @return array<int, int> project id => spoken turns
     */
    public function counts(iterable $projects): array
    {
        $ids = collect($projects)->map(fn (Project $project) => $project->id)->all();

        return $this->spoken()
            ->whereIn('project_id', $ids)
            ->selectRaw('project_id, count(*) as turns')
            ->groupBy('project_id')
            ->pluck('turns', 'project_id')
            ->map(fn ($turns) => (int) $turns)
            ->all();
    }

    /**
     * Everyone with a spoken turn in these runs, as a subquery of expert ids:
     * with the seated, the participants of the runs (see participants()).
     *
     * @param  list<int>  $projectIds
     * @return Builder<JobLog>
     */
    public function speakersIn(array $projectIds): Builder
    {
        return $this->spoken()->whereIn('project_id', $projectIds)->select('expert_id')->distinct();
    }

    /** @return Builder<JobLog> the counting rule itself, for every run */
    private function spoken(): Builder
    {
        return JobLog::query()->whereNotNull('words')->whereNotNull('expert_id');
    }

    /**
     * The spoken turns in the order they were taken. turn_index first, because
     * that is the runner's own count; the id breaks a tie for rows written
     * before turn_index existed.
     *
     * @return Builder<JobLog>
     */
    public function inOrder(Project $project): Builder
    {
        return $this->of($project)->orderBy('turn_index')->orderBy('id');
    }

    /**
     * The spoken turns inside a window, in the order they were taken; the whole
     * run for null. The one place a window is applied, so the totals and the
     * curve of a windowed run cover the same turns. The window counts spoken
     * turns, so a failed turn that never spoke takes no place in it.
     *
     * @return Builder<JobLog>
     */
    public function window(Project $project, ?TurnRange $turns = null): Builder
    {
        $turns ??= new TurnRange;

        // SQLite rejects an OFFSET without a LIMIT, so an open window gets the largest one.
        return $this->inOrder($project)
            ->offset($turns->from - 1)
            ->limit($turns->to === null ? PHP_INT_MAX : max(0, $turns->to - $turns->from + 1));
    }

    /**
     * Turns that failed before producing a contribution. They are invisible in
     * every share by construction, and the research plan asks for failure rates
     * to be reported rather than quietly filtered (Campedelli2024).
     */
    public function failedWithoutSpeaking(Project $project): int
    {
        return JobLog::where('project_id', $project->id)
            ->where('status', JobStatus::Failed)
            ->whereNull('words')
            ->count();
    }

    /**
     * Everyone seated, in seat order -- whether they spoke or not -- followed
     * by every expert who spoke in the run and has since left it, by id. Seat
     * order, so a participant keeps the same colour in every chart. A leaver
     * has no pivot, so its seat reads null; its turns are part of the run's
     * totals, so leaving it out would make the shares sum to less than one.
     *
     * Leavers are found over the whole run, never per window: in a window
     * after their last turn they still appear, with zero turns, as a seated
     * participant who stays silent does.
     *
     * @return Collection<int, Expert>
     */
    public function participants(Project $project): Collection
    {
        $seated = $project->contributingExperts()
            ->sortBy(fn (Expert $expert) => $expert->pivot?->seat ?? PHP_INT_MAX)
            ->values();

        // Read once per project instance, as contributingExperts() is: the
        // totals, the window and the curve of one answer ask for the same run.
        $this->leavers ??= new WeakMap;
        $this->leavers[$project] ??= Expert::query()
            ->whereIn('id', $this->of($project)->whereNotIn('expert_id', $seated->modelKeys())->select('expert_id'))
            ->orderBy('id')
            ->get();

        return $seated->concat($this->leavers[$project])->values();
    }
}
