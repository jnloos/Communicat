<?php

namespace App\Policies;

use App\Models\Expert;
use App\Models\User;

/**
 * Personas are shared by every run, so changing one changes the study's
 * material: every verified user reads them, only admins create, change or
 * delete them. The console (init:experts) and the admin-only bundle import
 * write experts outside this policy.
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
        return (bool) $user->is_admin;
    }

    public function delete(User $user, Expert $expert): bool
    {
        return (bool) $user->is_admin;
    }
}
