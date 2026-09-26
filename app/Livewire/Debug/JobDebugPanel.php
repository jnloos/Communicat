<?php

namespace App\Livewire\Debug;

use App\Livewire\Debug\Concerns\ShowsJobReport;
use App\Models\Project;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The job report as a page of its own, for any project the user can reach.
 *
 * The same report also opens as a flyout inside a discussion (JobDebugFlyout).
 * What this one adds is the project picker and a deep link; everything about
 * rendering a report lives in ShowsJobReport.
 */
class JobDebugPanel extends Component
{
    use ShowsJobReport;

    /** The project whose jobs are listed; kept in the URL so links can deep-link. */
    #[Url(as: 'project')]
    public ?int $projectId = null;

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

    /** @return Collection<int, Project> */
    protected function accessibleProjects(): Collection
    {
        return Project::whereHas('users', fn ($q) => $q->where('users.id', auth()->id()))
            ->orderBy('updated_at', 'desc')
            ->get(['id', 'title']);
    }

    public function render(): mixed
    {
        return view('livewire.debug.job-debug-panel', [
            'projects' => $this->accessibleProjects(),
            ...$this->jobReportFor($this->projectId),
        ])->title(__('debug.title'));
    }
}
