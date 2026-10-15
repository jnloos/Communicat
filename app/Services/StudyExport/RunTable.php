<?php

namespace App\Services\StudyExport;

use App\Discussion\Values\ShareReport;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Models\TagCategory;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** runs.csv: one row per run, one column per project tag category. */
final class RunTable
{
    /** The columns before the project tag columns, and those after them. */
    private const HEAD = ['run_uuid', 'title', 'description', 'pipeline', 'model', 'seed', 'turn_budget', 'group'];

    private const TAIL = ['turns_spoken', 'turns_failed_without_speaking'];

    private readonly TagColumns $tags;

    /** @param  Collection<int, TagCategory>  $categories  the project tag categories, in column order */
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
     * The group is the viewer's own sidebar drawer for the run.
     *
     * @param  Collection<int, Project>  $runs  with tagValues loaded
     * @param  Collection<int, ShareReport>  $reports  by project id
     * @return list<array<string, scalar|null>>
     */
    public function rows(Collection $runs, Collection $reports, User $viewer): array
    {
        $groups = ProjectGroup::where('user_id', $viewer->id)->pluck('name', 'id');
        $filing = $this->filingOf($viewer, $runs);

        return $runs->map(function (Project $run) use ($reports, $groups, $filing) {
            $report = $reports[$run->id];
            $groupId = $filing[$run->id] ?? null;

            return [
                'run_uuid' => $run->uuid,
                'title' => $run->title,
                'description' => $run->description,
                'pipeline' => $run->pipeline,
                'model' => $run->model,
                'seed' => $run->seed,
                'turn_budget' => $run->turn_budget,
                'group' => $groupId === null ? null : ($groups[$groupId] ?? null),
            ] + $this->tags->cells($run->tagValues) + [
                'turns_spoken' => $report->spokenTurns,
                'turns_failed_without_speaking' => $report->failedTurns,
            ];
        })->values()->all();
    }

    /**
     * The viewer's drawer per run, read in one query from the viewer's own
     * pivot rows (Project::groupIdFor() for all runs at once).
     *
     * @param  Collection<int, Project>  $runs
     * @return Collection<int, int|null> group id by project id
     */
    private function filingOf(User $viewer, Collection $runs): Collection
    {
        return DB::table('project_contributors')
            ->where('contributor_type', $viewer->getMorphClass())
            ->where('contributor_id', $viewer->id)
            ->whereIn('project_id', $runs->pluck('id'))
            ->pluck('project_group_id', 'project_id');
    }
}
