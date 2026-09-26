<?php

namespace App\Livewire\Debug;

use App\Models\JobLog;
use App\Models\Project;
use Flux\Flux;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The job report for one discussion, opened from the chat's control bar.
 *
 * The same report also has a standalone page (JobDebugPanel). The difference is
 * scope, not content: the page lets you pick a project, this one is pinned to the
 * discussion you are watching, so the report belongs to the run in front of you
 * instead of being a place you navigate away to.
 */
class JobDebugFlyout extends Component
{
    public const MODAL = 'job-debug-flyout';

    #[Locked]
    public int $projectId;

    /** When false, incoming job updates are ignored so the view stays put while reading. */
    public bool $live = true;

    public ?int $selectedJobId = null;

    /** Loaded on first open, not on mount: an unopened flyout should cost no queries. */
    public bool $opened = false;

    public function mount(Project $project): void
    {
        $this->projectId = $project->id;
    }

    #[On('open-job-debug')]
    public function open(): void
    {
        $this->opened = true;
        Flux::modal(self::MODAL)->show();
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
        // Closed or paused → don't re-render: a closed flyout has nothing to show,
        // and a paused one is being read.
        if (! $this->opened || ! $this->live) {
            $this->skipRender();
        }
    }

    public function render(): mixed
    {
        if (! $this->opened) {
            return view('livewire.debug.job-debug-flyout', ['logs' => collect(), 'selected' => null]);
        }

        $selected = $this->selectedJobId
            ? JobLog::with([
                'project',
                'promptLogs:id,job_log_id,label,model,prompt,response,latency_ms,created_at,status,error,provider,checkpoint,purpose,reasoning,tokens_in,tokens_out,tokens_reasoning',
                'messages.expert',
                'messages.addressee',
            ])
                ->where('project_id', $this->projectId)
                ->find($this->selectedJobId)
            : null;

        return view('livewire.debug.job-debug-flyout', [
            'logs' => JobLog::where('project_id', $this->projectId)->latest()->take(50)->get(),
            'selected' => $selected,
        ]);
    }
}
