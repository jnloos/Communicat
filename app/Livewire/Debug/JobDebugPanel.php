<?php

namespace App\Livewire\Debug;

use App\Models\JobLog;
use Flux\Flux;
use Livewire\Attributes\On;
use Livewire\Component;

class JobDebugPanel extends Component
{
    public function open(): void {
        Flux::modal('job-debug-panel')->show();
    }

    #[On('echo-private:debug,.JobLogUpdated')]
    public function onJobLogUpdated(): void {
        // Re-render triggered automatically
    }

    public function render(): mixed {
        return view('livewire.debug.job-debug-panel', [
            'logs' => JobLog::with('project')
                ->latest()
                ->take(50)
                ->get(),
        ]);
    }
}
