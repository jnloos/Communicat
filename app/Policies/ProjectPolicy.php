<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

/**
 * Who may do what with a project. Everyone with a part in it (owner or
 * invited contributor) reads it, follows its channel and exports it; the
 * owner alone changes, deletes and casts it. Project::scopeAccessibleBy() is
 * view() as a query.
 */
class ProjectPolicy
{
    public function view(User $user, Project $project): bool
    {
        return $project->hasContributor($user);
    }

    public function update(User $user, Project $project): bool
    {
        return $project->isOwner($user);
    }

    public function delete(User $user, Project $project): bool
    {
        return $this->update($user, $project);
    }

    /** Seating experts and inviting users is part of setting up the run. */
    public function manageContributors(User $user, Project $project): bool
    {
        return $this->update($user, $project);
    }

    /** An invited contributor may leave; the owner cannot leave their own project. */
    public function leave(User $user, Project $project): bool
    {
        return ! $project->isOwner($user);
    }
}
