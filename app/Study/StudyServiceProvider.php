<?php

namespace App\Study;

use App\Study\Console\CheckStudyPlan;
use App\Study\Console\PauseStudy;
use App\Study\Console\PlanStudyWave;
use App\Study\Console\QueueStudyRuns;
use App\Study\Console\ResumeStudy;
use App\Study\Control\BreakerStreak;
use App\Study\Control\CircuitBreaker;
use App\Study\Control\PauseFlag;
use App\Study\Jobs\RunStudyProject;
use App\Study\Livewire\PlanWavePage;
use App\Study\Livewire\ProgressPage;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Queue;
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

        $this->app->singleton(BreakerStreak::class);
        $this->app->singleton(PauseFlag::class);
        $this->app->singleton(CircuitBreaker::class);
    }

    public function boot(): void
    {
        // Always, like the queue connection: the schema must not depend on the switch.
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        // Always, like the connection: a paused study worker takes no job and retries none
        // (Worker::daemon sleeps --sleep instead), so 3,000 queued turns do not spin.
        Queue::looping(fn (Looping $event) => $event->connectionName === RunStudyProject::CONNECTION && $this->app->make(PauseFlag::class)->active() ? false : null);

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
            $this->commands([QueueStudyRuns::class, PlanStudyWave::class, CheckStudyPlan::class, PauseStudy::class, ResumeStudy::class]);
        }
    }
}
