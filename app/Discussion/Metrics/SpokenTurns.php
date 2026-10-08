<?php

namespace App\Discussion\Metrics;

use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

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
 *   so tokens would bias the model comparison the study exists to make.
 * - **Every contributing expert appears, including one that never spoke.** A
 *   zero is a finding, not a missing row — and it is what pulls the Gini
 *   coefficient up.
 */
class SpokenTurns
{
    /**
     * The project's spoken turns. Unordered: a caller that aggregates needs no
     * order, and one that walks the turns calls inOrder().
     *
     * @return Builder<JobLog>
     */
    public function of(Project $project): Builder
    {
        return JobLog::query()
            ->where('project_id', $project->id)
            ->whereNotNull('words')
            ->whereNotNull('expert_id');
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
     * The first $limit spoken turns in the order they were taken, or all of
     * them for null. The one place a turn limit is applied, so the totals and
     * the curve of a limited run stop at the same turn.
     *
     * @return Builder<JobLog>
     */
    public function firstTurns(Project $project, ?int $limit = null): Builder
    {
        if ($limit !== null && $limit < 1) {
            throw new InvalidArgumentException("A turn limit must be at least 1, got {$limit}.");
        }

        return $this->inOrder($project)->when($limit !== null, fn (Builder $turns) => $turns->limit($limit));
    }

    /**
     * Turns that failed before producing a contribution. They are invisible in
     * every share by construction, and the research plan asks for failure rates
     * to be reported rather than quietly filtered (Campedelli2024).
     */
    public function failedWithoutSpeaking(Project $project): int
    {
        return JobLog::where('project_id', $project->id)
            ->where('status', 'failed')
            ->whereNull('words')
            ->count();
    }

    /**
     * Everyone seated, in seat order — whether they spoke or not. Seat order,
     * so a participant keeps the same colour in every chart.
     *
     * @return Collection<int, Expert>
     */
    public function participants(Project $project): Collection
    {
        return $project->contributingExperts()
            ->sortBy(fn (Expert $expert) => $expert->pivot?->seat ?? PHP_INT_MAX)
            ->values();
    }
}
