<?php

namespace App\Study\Livewire;

use App\Livewire\Concerns\NeedsConfirmation;
use App\Study\Control\CircuitBreaker;
use App\Study\Control\PauseFlag;
use App\Study\Control\PauseSource;
use App\Study\Models\StudyEvent;
use App\Study\Progress\StudyProgress;
use App\Study\Progress\StudySnapshot;
use App\Study\Watch\AlertLevel;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

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

    /** Why the last pause or resume did not happen: the pause file could not be written or removed. */
    public ?string $controlError = null;

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

        $this->controlError = null;

        try {
            if (app(PauseFlag::class)->pause('manual', 'manual', PauseSource::Manual)) {
                $this->recordManual('manual_paused');
            }
        } catch (RuntimeException $e) {
            $this->controlError = $e->getMessage();
        }
    }

    public function resumeStudy(): void
    {
        Gate::authorize('admin');

        $this->controlError = null;

        try {
            if (app(PauseFlag::class)->active()) {
                app(CircuitBreaker::class)->resume();
                $this->recordManual('manual_resumed');
            }
        } catch (RuntimeException $e) {
            $this->controlError = $e->getMessage();
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
        $progress = app(StudyProgress::class)->read();
        $totals = $progress['totals'] ?? ['total' => 0, 'done' => 0];

        $this->confirmLabel = __("study::progress.{$action}_label");
        $this->confirmMessage = __("study::progress.{$action}_message", ['runs' => $totals['total'] - $totals['done'], 'turns' => $progress['remaining'] ?? 0]);
        $this->needsConfirmation($method);
    }

    private function recordManual(string $key): void
    {
        StudyEvent::create(['kind' => 'manual', 'level' => AlertLevel::Info->value, 'message' => __("study::watch.{$key}", ['user' => auth()->user()->name])]);
    }
}
