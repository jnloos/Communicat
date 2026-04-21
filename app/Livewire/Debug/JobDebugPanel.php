<?php

namespace App\Livewire\Debug;

use App\Models\JobLog;
use Flux\Flux;
use Livewire\Attributes\On;
use Livewire\Component;

class JobDebugPanel extends Component
{
    public ?int $selectedJobId = null;

    public function open(): void {
        Flux::modal('job-debug-panel')->show();
    }

    public function selectJob(int $jobId): void {
        $this->selectedJobId = $this->selectedJobId === $jobId ? null : $jobId;
    }

    #[On('echo-private:debug,.JobLogUpdated')]
    public function onJobLogUpdated(): void {
        // Re-render triggered automatically
    }

    public function render(): mixed {
        $selected = $this->selectedJobId
            ? JobLog::with([
                'project',
                'promptLogs:id,job_log_id,label,model,prompt,response,latency_ms,created_at',
                'messages.expert',
            ])->find($this->selectedJobId)
            : null;

        return view('livewire.debug.job-debug-panel', [
            'logs'     => JobLog::with('project')
                ->latest()
                ->take(50)
                ->get(),
            'selected' => $selected,
        ]);
    }
}
