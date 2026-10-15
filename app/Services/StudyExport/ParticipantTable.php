<?php

namespace App\Services\StudyExport;

use App\Discussion\Values\ShareReport;
use App\Discussion\Values\SpeakerShare;
use App\Models\Expert;
use App\Models\Project;
use App\Models\TagCategory;
use Illuminate\Support\Collection;

/**
 * participants.csv: one row per participant as SpeakingShares reports them,
 * the seated in seat order, then everyone who spoke and has left the run,
 * seat empty. One column per persona tag category.
 */
final class ParticipantTable
{
    /** The columns before the persona tag columns, and those after them. */
    private const HEAD = ['run_uuid', 'expert_uuid', 'expert_token', 'name', 'role', 'seat'];

    private const TAIL = ['turns', 'turn_share', 'words', 'word_share', 'words_per_turn'];

    private readonly TagColumns $tags;

    /** @param  Collection<int, TagCategory>  $categories  the persona tag categories, in column order */
    public function __construct(Collection $categories)
    {
        $this->tags = new TagColumns($categories, [...self::HEAD, ...self::TAIL]);
    }

    /** @return list<string> */
    public function columns(): array
    {
        return [...self::HEAD, ...$this->tags->headers(), ...self::TAIL];
    }

    /**
     * @param  Collection<int, Project>  $runs
     * @param  Collection<int, ShareReport>  $reports  by project id
     * @return list<array<string, scalar|null>>
     */
    public function rows(Collection $runs, Collection $reports): array
    {
        $experts = $this->expertsOf($reports);

        return $runs->flatMap(fn (Project $run) => array_map(
            fn (SpeakerShare $share) => $this->row($run, $share, $experts[$share->expertId]),
            $reports[$run->id]->speakers,
        ))->values()->all();
    }

    /**
     * Every participant of every report with its tags, in one query each.
     *
     * @param  Collection<int, ShareReport>  $reports
     * @return Collection<int, Expert>
     */
    private function expertsOf(Collection $reports): Collection
    {
        $ids = $reports->flatMap(fn (ShareReport $report) => array_map(fn (SpeakerShare $share) => $share->expertId, $report->speakers))->unique();

        return Expert::with('tagValues')->whereIn('id', $ids)->get()->keyBy('id');
    }

    /** @return array<string, scalar|null> */
    private function row(Project $run, SpeakerShare $share, Expert $expert): array
    {
        return [
            'run_uuid' => $run->uuid,
            'expert_uuid' => $expert->uuid,
            'expert_token' => $expert->promptId,
            'name' => $expert->name,
            'role' => $expert->role,
            'seat' => $share->seat,
        ] + $this->tags->cells($expert->tagValues) + [
            'turns' => $share->turns,
            'turn_share' => $share->turnShare,
            'words' => $share->words,
            'word_share' => $share->wordShare,
            'words_per_turn' => $share->turns === 0 ? null : $share->wordsPerTurn(),
        ];
    }
}
