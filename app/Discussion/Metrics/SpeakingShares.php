<?php

namespace App\Discussion\Metrics;

use App\Discussion\Values\ShareReport;
use App\Discussion\Values\SpeakerShare;
use App\Discussion\Values\TurnRange;
use App\Models\Expert;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * Turn share and conversation share for one run, read straight from job_logs.
 *
 * The panel, the CLI and the thesis's evaluation script all read it, because
 * three separate implementations would drift and only one of them could be
 * right. Which rows count, and why, is SpokenTurns' business — this class only
 * adds them up.
 */
class SpeakingShares
{
    public function __construct(private SpokenTurns $spoken) {}

    /**
     * @param  TurnRange|null  $turns  count only the spoken turns inside this window; null counts the whole run.
     *                                 failedTurns stays the run's total either way.
     */
    public function forProject(Project $project, ?TurnRange $turns = null): ShareReport
    {
        // Aggregated over a subquery, so a window cuts the turns before they are
        // added up rather than the groups after.
        $counted = DB::query()
            ->fromSub($this->spoken->window($project, $turns)->select(['expert_id', 'words']), 'spoken')
            ->selectRaw('expert_id, count(*) as turns, sum(words) as words')
            ->groupBy('expert_id')
            ->get()
            ->keyBy('expert_id');

        $spokenTurns = (int) $counted->sum('turns');
        $failedTurns = $this->spoken->failedWithoutSpeaking($project);
        $totalWords = (int) $counted->sum('words');

        $speakers = $this->spoken->participants($project)
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
