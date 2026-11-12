<?php

namespace App\Study\Livewire;

use App\Study\Progress\StudyProgress;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Study-only, removable: see app/Study/README.md.
 *
 * Read-only progress of a study batch, refreshed every 30 s. Admins only: the
 * route checks it for the first request, boot() for every update after it.
 */
class ProgressPage extends Component
{
    /** A `wave` tag value id; null shows every wave. */
    #[Url]
    public ?int $wave = null;

    public function boot(): void
    {
        Gate::authorize('admin');
    }

    public function render(): mixed
    {
        return view('study::progress', ['report' => app(StudyProgress::class)->read($this->wave)])
            ->title(__('study::progress.heading'));
    }
}
