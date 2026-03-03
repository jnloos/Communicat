<?php
namespace App\Providers;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void {
        Gate::define('access-project', function (User $user, Project $project) {
            return $project->hasContributor($user);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void {
        //
    }
}
