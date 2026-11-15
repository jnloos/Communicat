<?php

namespace App\Policies;

use App\Models\Expert;
use App\Models\User;

/**
 * Personas are shared by every run, so changing one changes the study's
 * material: every verified user reads them, only admins create, change or
 * delete them. A persona a started run has read is locked (Expert::isLocked()):
 * nobody changes or deletes it, admins still change its tags, which reach no
 * model. The console (init:experts) and the admin-only bundle import write
 * experts outside this policy; the lock itself sits in the model.
 */
class ExpertPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Expert $expert): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return (bool) $user->is_admin;
    }

    public function update(User $user, Expert $expert): bool
    {
        return (bool) $user->is_admin && ! $expert->isLocked();
    }

    public function delete(User $user, Expert $expert): bool
    {
        return (bool) $user->is_admin && ! $expert->isLocked();
    }

    public function updateTags(User $user, Expert $expert): bool
    {
        return (bool) $user->is_admin;
    }
}
