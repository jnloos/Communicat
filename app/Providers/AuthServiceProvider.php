<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * The one gate that belongs to no model. Everything a user may do with a
 * project, a group, an expert or a question lives in its policy under
 * App\Policies, which Laravel finds by name.
 */
class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Gate::define('admin', function (User $user) {
            return $user->is_admin;
        });
    }
}
