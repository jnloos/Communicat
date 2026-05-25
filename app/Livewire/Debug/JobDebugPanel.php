<?php

namespace App\Livewire\Debug;

use App\Models\JobLog;
use App\Models\Project;
use Livewire\Attributes\On;
use Livewire\Component;

class JobDebugPanel extends Component
{
    /** Modal visibility, bound via wire:model so it survives re-renders. */
    public bool $show = false;

    /** The project currently being viewed; the panel only shows its jobs. */
    public ?int $projectId = null;

    /** When false, incoming job updates are ignored so the view stays frozen for reading. */
    public bool $live = true;

    public ?int $selectedJobId = null;

    public function mount(): void
    {
        // The panel lives in the global sidebar, so it derives the current
        // project from the route-model-bound {project} parameter.
        $project = request()->route('project');
        $this->projectId = $project instanceof Project ? $project->id : null;
    }

    public function open(): void
    {
        $this->show = true;
    }

    public function togglePause(): void
    {
        $this->live = ! $this->live;
    }

    public function selectJob(int $jobId): void
    {
        $this->selectedJobId = $this->selectedJobId === $jobId ? null : $jobId;
    }

    #[On('echo-private:debug,.JobLogUpdated')]
    public function onJobLogUpdated(): void
    {
        // Paused or panel closed → don't re-render, so an open job stays put
        // while the user reads it.
        if (! $this->live || ! $this->show) {
            $this->skipRender();
        }
    }

    public function render(): mixed
    {
        $selected = $this->selectedJobId && $this->projectId
            ? JobLog::with([
                'project',
                'promptLogs:id,job_log_id,label,model,prompt,response,latency_ms,created_at',
                'messages.expert',
            ])
                ->where('project_id', $this->projectId)
                ->find($this->selectedJobId)
            : null;

        // Only the current project's jobs; none when not on a project page.
        $logs = $this->projectId
            ? JobLog::with('project')
                ->where('project_id', $this->projectId)
                ->latest()
                ->take(50)
                ->get()
            : collect();

        return view('livewire.debug.job-debug-panel', [
            'logs'     => $logs,
            'selected' => $selected,
        ]);
    }
}
