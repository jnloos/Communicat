<?php

namespace App\Discussion\Metrics\Questions;

use App\Discussion\Values\ProjectFilter;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;

/** The runs a question reads: what the user may access, narrowed by the question's project filter. */
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
            ->when($filter->turnBudget !== null, fn ($query) => $query->where('turn_budget', $filter->turnBudget))
            ->orderBy('id')
            ->get();
    }
}
