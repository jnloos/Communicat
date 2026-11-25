<?php

namespace App\Study;

use App\Study\Console\CheckStudyPlan;
use App\Study\Console\PauseStudy;
use App\Study\Console\PlanStudyWave;
use App\Study\Console\QueueStudyRuns;
use App\Study\Console\RegisterDiscordCommands;
use App\Study\Console\ResumeStudy;
use App\Study\Console\WatchStudy;
use App\Study\Control\BreakerStreak;
use App\Study\Control\CircuitBreaker;
use App\Study\Control\PauseFlag;
use App\Study\Discord\InteractionController;
use App\Study\Events\BreakerTripped;
use App\Study\Jobs\RunStudyProject;
use App\Study\Livewire\PlanWavePage;
use App\Study\Livewire\ProgressPage;
use App\Study\Watch\AnnounceHalt;
use App\Study\Watch\WorkerHeartbeat;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Pail\Files;
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
        // One instance per process: it remembers when it last wrote.
        $this->app->singleton(WorkerHeartbeat::class);
    }

    /**
     * Pail (dev only) copies every log line into storage/pail/*.pail and unlinks a stale
     * one non-atomically: study workers logging at once race on it, and the warning fails
     * the turn. A study worker has no live viewer, so it gets an empty directory instead.
     * Here in boot(), so it overrides Pail's own binding whatever the provider order.
     */
    public function silencePail(): void
    {
        $argv = $_SERVER['argv'] ?? [];

        if (class_exists(Files::class) && ($argv[1] ?? null) === 'queue:work' && in_array(RunStudyProject::CONNECTION, $argv, true)) {
            $this->app->singleton(Files::class, fn () => new Files(storage_path('framework/no-pail')));
        }
    }

    public function boot(): void
    {
        // Always, like the queue connection: the schema must not depend on the switch.
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        // Always, like the connection: workers outlive the switch. Registered before the pause hook below,
        // because Worker asks `until(Looping)` and the first answer that is not null ends the round.
        Event::listen(Looping::class, [WorkerHeartbeat::class, 'handle']);

        // Always, like the connection: a paused study worker takes no job and retries none
        // (Worker::daemon sleeps --sleep instead), so 3,000 queued turns do not spin.
        Queue::looping(fn (Looping $event) => $event->connectionName === RunStudyProject::CONNECTION && $this->app->make(PauseFlag::class)->active() ? false : null);

        $this->silencePail();

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

        Event::listen(BreakerTripped::class, [AnnounceHalt::class, 'handle']);

        // Outside the web stack: no session, no CSRF; the Ed25519 signature is the protection.
        if (config('study.discord.public_key')) {
            Route::post('discord/interactions', InteractionController::class)
                ->middleware('throttle:60,1')
                ->name('study.discord.interactions');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([QueueStudyRuns::class, PlanStudyWave::class, CheckStudyPlan::class, PauseStudy::class, ResumeStudy::class, WatchStudy::class, RegisterDiscordCommands::class]);

            $this->callAfterResolving(Schedule::class, fn (Schedule $schedule) => $schedule->command('study:watch')->everyMinute()->withoutOverlapping(5));
        }
    }
}
