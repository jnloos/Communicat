<?php

namespace App\Policies;

use App\Models\ProjectGroup;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * A group is a drawer in one person's sidebar. Someone else's does not exist
 * as far as a user is concerned, so every denial is a 404: a 403 would
 * confirm the id.
 */
class ProjectGroupPolicy
{
    /** Renaming the group, or filing a project into it or out of it. */
    public function update(User $user, ProjectGroup $group): Response
    {
        return $group->user_id === $user->id ? Response::allow() : Response::denyAsNotFound();
    }

    public function delete(User $user, ProjectGroup $group): Response
    {
        return $this->update($user, $group);
    }
}
