<?php

namespace App\Study;

use App\Study\Console\CheckStudyPlan;
use App\Study\Console\PlanStudyWave;
use App\Study\Console\QueueStudyRuns;
use App\Study\Livewire\PlanWavePage;
use App\Study\Livewire\ProgressPage;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Study-only, removable: see app/Study/README.md.
 *
 * The one place the study module plugs into the app. Core code never names the
 * module; it only offers `@includeIf('study::nav-item')` in the sidebar, which
 * renders nothing while the module is off or gone.
 */
class StudyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config.php', 'study');

        // Always registered, even with the module off: jobs already in the table
        // name this connection and would fail without it.
        if (! $this->app->configurationIsCached()) {
            config(['queue.connections.study' => config('study.queue')]);
        }
    }

    public function boot(): void
    {
        // Always, like the queue connection: the schema must not depend on the switch.
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        if (! config('study.enabled')) {
            return;
        }

        $this->loadViewsFrom(__DIR__.'/views', 'study');
        $this->loadTranslationsFrom(__DIR__.'/lang', 'study');

        Livewire::component('study.progress-page', ProgressPage::class);
        Livewire::component('study.plan-wave-page', PlanWavePage::class);

        Route::middleware(['web', 'auth', 'verified', 'can:admin'])
            ->prefix('study')
            ->name('study.')
            ->group(__DIR__.'/routes.php');

        if ($this->app->runningInConsole()) {
            $this->commands([QueueStudyRuns::class, PlanStudyWave::class, CheckStudyPlan::class]);
        }
    }
}
