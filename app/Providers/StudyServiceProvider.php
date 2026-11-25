<?php

namespace App\Providers;

use App\Events\BreakerTripped;
use App\Http\Controllers\DiscordInteractionController;
use App\Jobs\RunStudyProject;
use App\Livewire\Study\PlanWavePage;
use App\Livewire\Study\ProgressPage;
use App\Services\Study\Control\BreakerStreak;
use App\Services\Study\Control\CircuitBreaker;
use App\Services\Study\Control\PauseFlag;
use App\Services\Study\Watch\AnnounceHalt;
use App\Services\Study\Watch\WorkerHeartbeat;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Pail\Files;
use Livewire\Livewire;

/**
 * Thesis study, see docs/study.md. What Laravel does not find by itself: the `study`
 * queue connection, the worker hooks, and, while STUDY_MODULE is on, the pages, the
 * Discord endpoint and the alert listener. Config, views, translations, migrations and
 * commands sit in Laravel's own places; study:watch is scheduled in routes/console.php.
 */
class StudyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
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

        Livewire::component('study.progress-page', ProgressPage::class);
        Livewire::component('study.plan-wave-page', PlanWavePage::class);

        Route::middleware(['web', 'auth', 'verified', 'can:admin'])
            ->prefix('study')
            ->name('study.')
            ->group(base_path('routes/study.php'));

        Event::listen(BreakerTripped::class, [AnnounceHalt::class, 'handle']);

        // Outside the web stack: no session, no CSRF; the Ed25519 signature is the protection.
        if (config('study.discord.public_key')) {
            Route::post('discord/interactions', DiscordInteractionController::class)
                ->middleware('throttle:60,1')
                ->name('study.discord.interactions');
        }
    }
}
