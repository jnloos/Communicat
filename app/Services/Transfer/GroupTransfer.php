<?php

namespace App\Services\Transfer;

use App\Models\Project;
use App\Models\ProjectGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** One user's sidebar groups and which projects they filed there, by project uuid. */
final class GroupTransfer
{
    /** @return list<array{name: string, projects: list<string>}> */
    public function export(User $user): array
    {
        $filed = DB::table('project_contributors')
            ->join('projects', 'projects.id', '=', 'project_contributors.project_id')
            ->where('contributor_type', $user->getMorphClass())
            ->where('contributor_id', $user->id)
            ->whereNotNull('project_group_id')
            ->get(['project_group_id', 'projects.uuid'])
            ->groupBy('project_group_id');

        return ProjectGroup::where('user_id', $user->id)->orderBy('position')->orderBy('id')->get()
            ->map(fn (ProjectGroup $group) => [
                'name' => $group->name,
                'projects' => ($filed[$group->id] ?? collect())->pluck('uuid')->sort()->values()->all(),
            ])
            ->values()->all();
    }

    public function import(array $groups, User $user, ImportReport $report): void
    {
        foreach ($groups as $data) {
            $name = trim((string) ($data['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $group = ProjectGroup::where('user_id', $user->id)->where('name', $name)->first();
            $created = $group === null;
            $group ??= ProjectGroup::create([
                'user_id' => $user->id,
                'name' => $name,
                'position' => (int) ProjectGroup::where('user_id', $user->id)->max('position') + 1,
            ]);
            $filed = 0;

            foreach (Project::whereIn('uuid', $data['projects'] ?? [])->get() as $project) {
                if ($project->hasContributor($user)) {
                    $project->fileInGroupFor($user, $group->id);
                    $filed++;
                }
            }

            $report->add(ImportReport::GROUPS, $name, $created ? ImportReport::CREATED : ImportReport::SKIPPED, trans_choice('transfer.notes.projects_filed', $filed, ['count' => $filed]));
        }
    }
}
