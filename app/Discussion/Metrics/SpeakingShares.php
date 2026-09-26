<?php

namespace App\Discussion\Metrics;

use App\Discussion\Values\ShareReport;
use App\Discussion\Values\SpeakerShare;
use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Project;

/**
 * Turn share and conversation share for one run, read straight from job_logs.
 *
 * This is the single implementation of the study's counting rules. The panel,
 * the CLI and the thesis's evaluation script all read it, because three
 * separate implementations would drift and only one of them could be right.
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
class SpeakingShares
{
    public function forProject(Project $project): ShareReport
    {
        $experts = $project->contributingExperts()->values();

        $counted = JobLog::query()
            ->where('project_id', $project->id)
            ->whereNotNull('words')
            ->whereNotNull('expert_id')
            ->selectRaw('expert_id, count(*) as turns, sum(words) as words')
            ->groupBy('expert_id')
            ->get()
            ->keyBy('expert_id');

        $spokenTurns = (int) $counted->sum('turns');
        $failedTurns = JobLog::where('project_id', $project->id)
            ->where('status', 'failed')
            ->whereNull('words')
            ->count();
        $totalWords = (int) $counted->sum('words');

        $speakers = $experts
            ->map(function (Expert $expert) use ($counted, $spokenTurns, $totalWords) {
                $turns = (int) ($counted[$expert->id]->turns ?? 0);
                $words = (int) ($counted[$expert->id]->words ?? 0);

                return new SpeakerShare(
                    expertId: $expert->id,
                    name: $expert->name,
                    seat: $expert->pivot?->seat,
                    turns: $turns,
                    words: $words,
                    turnShare: $spokenTurns === 0 ? 0.0 : $turns / $spokenTurns,
                    wordShare: $totalWords === 0 ? 0.0 : $words / $totalWords,
                );
            })
            ->sortBy(fn (SpeakerShare $share) => $share->seat ?? PHP_INT_MAX)
            ->values()
            ->all();

        return new ShareReport(
            speakers: $speakers,
            spokenTurns: $spokenTurns,
            failedTurns: $failedTurns,
            totalWords: $totalWords,
            turnGini: Gini::of(array_map(fn (SpeakerShare $s) => $s->turns, $speakers)),
            wordGini: Gini::of(array_map(fn (SpeakerShare $s) => $s->words, $speakers)),
            giniCeiling: Gini::maximumFor(count($speakers)),
        );
    }
}
