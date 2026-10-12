<?php

namespace App\Discussion\Metrics\Questions;

use App\Discussion\Values\ProjectFilter;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;

/** The runs a question reads: what the user may access, narrowed by the question's project filter (groups are the user's own). */
final class QuestionRuns
{
    /** @return Collection<int, Project> */
    public function for(User $user, ProjectFilter $filter): Collection
    {
        return Project::query()
            ->accessibleBy($user)
            ->whereTagFilter($filter->tags)
            ->when($filter->pipelines !== [], fn ($query) => $query->whereIn('pipeline', $filter->pipelines))
            ->when($filter->models !== [], fn ($query) => $query->whereIn('model', $filter->models))
            // The group sits on this user's own pivot row, so another user's filing never matches.
            ->when($filter->groups !== [], fn ($query) => $query->whereHas('users', fn ($users) => $users
                ->whereKey($user->id)
                ->whereIn('project_contributors.project_group_id', $filter->groups)))
            ->orderBy('id')
            ->get();
    }
}
