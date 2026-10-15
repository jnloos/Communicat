<?php

namespace App\Livewire\Debug;

use App\Livewire\Debug\Concerns\ShowsJobReport;
use App\Models\Project;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
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

        if (! $this->accessibleProjects->contains('id', $this->projectId)) {
            $this->projectId = $this->accessibleProjects->first()?->id;
        }
    }

    /**
     * The id comes from the client, so every change is checked against the access
     * rule. The page then reloads for the new project: live updates come over the
     * project's own channel, and Echo subscriptions are fixed when a component mounts.
     */
    public function updatedProjectId(): void
    {
        $this->authorizeProject();
        $this->redirectRoute('debug.jobs', ['project' => $this->projectId], navigate: true);
    }

    /** @return Collection<int, Project> */
    #[Computed]
    public function accessibleProjects(): Collection
    {
        return Project::accessibleBy(auth()->user())
            ->orderBy('updated_at', 'desc')
            ->get(['id', 'title']);
    }

    private function authorizeProject(): void
    {
        if ($this->projectId !== null) {
            Gate::authorize('access-project', Project::findOrFail($this->projectId));
        }
    }

    public function render(): mixed
    {
        $this->authorizeProject();

        return view('livewire.debug.job-debug-panel', [
            'projects' => $this->accessibleProjects,
            ...$this->jobReportFor($this->projectId),
        ])->title(__('debug.title'));
    }
}
