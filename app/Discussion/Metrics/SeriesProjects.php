<?php

namespace App\Discussion\Metrics;

use App\Discussion\Values\SeriesDefinition;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;

/** The runs a series covers: what the user may read, narrowed by the series' project filter. */
class SeriesProjects
{
    /** @return Collection<int, Project> */
    public function for(User $user, SeriesDefinition $series): Collection
    {
        return Project::query()
            ->accessibleBy($user)
            ->whereTagFilter($series->projectTags)
            // A tag this database does not know can match no run — never all of them.
            ->when($series->hasUnresolvedTags(), fn ($query) => $query->whereRaw('1 = 0'))
            ->when($series->pipeline !== null, fn ($query) => $query->where('pipeline', $series->pipeline))
            ->when($series->model !== null, fn ($query) => $query->where('model', $series->model))
            ->orderBy('id')
            ->get();
    }
}
