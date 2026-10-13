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
            try {
                $entry = EntryFields::entry($data);
                $name = trim((string) EntryFields::text($entry, 'name'));
                $projects = EntryFields::texts($entry, 'projects');
            } catch (UnreadableEntry) {
                $report->add(ImportReport::GROUPS, EntryFields::label($data, 'name'), ImportReport::FAILED, __('transfer.notes.unreadable_entry'));

                continue;
            }

            if ($name === '') {
                $report->add(ImportReport::GROUPS, '', ImportReport::FAILED, __('transfer.notes.group_unnamed'));

                continue;
            }

            $this->importOne($name, $projects, $user, $report);
        }
    }

    /** @param  list<string>  $projects  uuids */
    private function importOne(string $name, array $projects, User $user, ImportReport $report): void
    {
        $group = ProjectGroup::where('user_id', $user->id)->where('name', $name)->first();
        $created = $group === null;
        $group ??= ProjectGroup::create([
            'user_id' => $user->id,
            'name' => $name,
            'position' => (int) ProjectGroup::where('user_id', $user->id)->max('position') + 1,
        ]);
        // Only projects the user contributes to (Project::hasContributor()), found in one query.
        $own = Project::whereIn('uuid', $projects)
            ->where(fn ($query) => $query->where('user_id', $user->id)->orWhereHas('users', fn ($users) => $users->whereKey($user->id)))
            ->get();

        foreach ($own as $project) {
            $project->fileInGroupFor($user, $group->id);
        }

        $filed = $own->count();

        $report->add(ImportReport::GROUPS, $name, $created ? ImportReport::CREATED : ImportReport::SKIPPED, trans_choice('transfer.notes.projects_filed', $filed, ['count' => $filed]));
    }
}
