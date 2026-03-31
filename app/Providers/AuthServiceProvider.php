<?php
namespace App\Providers;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    public function register(): void {
        Gate::define('access-project', function (User $user, Project $project) {
            return $project->hasContributor($user);
        });

        Gate::define('manage-project', function (User $user, Project $project) {
            return $project->isOwner($user);
        });

        Gate::define('manage-contributors', function (User $user, Project $project) {
            return $project->isOwner($user);
        });

        Gate::define('admin', function (User $user) {
            return $user->is_admin;
        });
    }

    public function boot(): void {
        //
    }
}
