<?php

namespace App\Study\Livewire;

use App\Livewire\Concerns\NeedsConfirmation;
use App\Study\Control\PauseFlag;
use App\Study\Control\PauseSource;
use App\Study\Models\StudyEvent;
use App\Study\Progress\StudySnapshot;
use App\Study\Watch\AlertLevel;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Study-only, removable: see app/Study/README.md.
 *
 * Progress of a study batch, refreshed every 30 s, with the one control there is: pause and
 * resume of the whole study (all waves, whatever the wave filter says). Admins only: the route
 * checks it for the first request, boot() for every update after it.
 */
class ProgressPage extends Component
{
    use NeedsConfirmation;

    /** A `wave` tag value id; null shows every wave. */
    #[Url]
    public ?int $wave = null;

    public function boot(): void
    {
        Gate::authorize('admin');
    }

    public function askToPause(): void
    {
        $this->askToConfirm('pause', 'pauseStudy');
    }

    public function askToResume(): void
    {
        $this->askToConfirm('resume', 'resumeStudy');
    }

    /** Confirmed actions: executeConfirmed() skips Livewire's attributes and injection, so each authorizes itself. */
    public function pauseStudy(): void
    {
        Gate::authorize('admin');

        if (app(PauseFlag::class)->pause('manual', 'manual', PauseSource::Manual)) {
            $this->recordManual('manual_paused');
        }
    }

    public function resumeStudy(): void
    {
        Gate::authorize('admin');

        if (app(PauseFlag::class)->active()) {
            app(PauseFlag::class)->resume();
            $this->recordManual('manual_resumed');
        }
    }

    public function render(): mixed
    {
        $snapshot = StudySnapshot::read($this->wave);

        return view('study::progress', ['snapshot' => $snapshot, 'report' => $snapshot->progress])
            ->title(__('study::progress.heading'));
    }

    private function askToConfirm(string $action, string $method): void
    {
        $progress = StudySnapshot::read()->progress;

        $this->confirmLabel = __("study::progress.{$action}_label");
        $this->confirmMessage = __("study::progress.{$action}_message", ['runs' => $progress['totals']['total'] ?? 0, 'turns' => $progress['remaining'] ?? 0]);
        $this->needsConfirmation($method);
    }

    private function recordManual(string $key): void
    {
        StudyEvent::create(['kind' => 'manual', 'level' => AlertLevel::Info->value, 'message' => __("study::watch.{$key}", ['user' => auth()->user()->name])]);
    }
}
