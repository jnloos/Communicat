<?php

namespace App\Livewire\Debug;

use App\Models\JobLog;
use App\Models\Project;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

class JobDebugPanel extends Component
{
    /** The project whose jobs are listed; kept in the URL so links can deep-link. */
    #[Url(as: 'project')]
    public ?int $projectId = null;

    /** When false, incoming job updates are ignored so the view stays frozen for reading. */
    public bool $live = true;

    public ?int $selectedJobId = null;

    public function mount(): void
    {
        abort_unless(config('app.debug'), 404);

        if (! $this->accessibleProjects()->contains('id', $this->projectId)) {
            $this->projectId = $this->accessibleProjects()->first()?->id;
        }
    }

    public function updatedProjectId(): void
    {
        $this->selectedJobId = null;
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
        // Paused → don't re-render, so an open job stays put while reading.
        if (! $this->live) {
            $this->skipRender();
        }
    }

    /** @return Collection<int, Project> */
    protected function accessibleProjects(): Collection
    {
        return Project::whereHas('users', fn ($q) => $q->where('users.id', auth()->id()))
            ->orderBy('updated_at', 'desc')
            ->get(['id', 'title']);
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

        $logs = $this->projectId
            ? JobLog::with('project')
                ->where('project_id', $this->projectId)
                ->latest()
                ->take(50)
                ->get()
            : collect();

        return view('livewire.debug.job-debug-panel', [
            'projects' => $this->accessibleProjects(),
            'logs'     => $logs,
            'selected' => $selected,
        ])->title(__('debug.title'));
    }
}
