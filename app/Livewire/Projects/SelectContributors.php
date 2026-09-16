<?php

namespace App\Livewire\Projects;

use App\Events\ContributorsChanged;
use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class SelectContributors extends Component
{
    #[Locked]
    public int $forProjectId;

    protected Project $forProject;

    public string $search = '';

    public string $userSearch = '';

    public ?string $limitWarning = null;

    public function hasActiveFilters(): bool
    {
        return trim($this->search) !== '';
    }

    public function mount(Project $project): void
    {
        $this->forProjectId = $project->id;
        $this->forProject = $project;
    }

    public function hydrate(): void
    {
        $this->refreshProject();
    }

    public function refreshProject(): void
    {
        $this->forProject = Project::find($this->forProjectId);
    }

    #[On('select_contributors')]
    public function select(): void
    {
        Flux::modal('select-contributors')->show();
    }

    public function addExpert(int $expertId): void
    {
        if (! $this->forProject->canAddExpert()) {
            $this->limitWarning = __(
                'Maximal :n Experten pro Projekt.',
                ['n' => Project::MAX_CONTRIBUTING_EXPERTS]
            );
            return;
        }

        $expert = Expert::findOrFail($expertId);
        $this->forProject->addContributingExpert($expert);
        $this->limitWarning = null;
        ContributorsChanged::dispatch($this->forProjectId);
        $this->dispatch('contributors_modified');
    }

    public function removeExpert(int $expertId): void
    {
        $expert = Expert::findOrFail($expertId);
        $this->forProject->removeContributingExpert($expert);
        $this->limitWarning = null;
        ContributorsChanged::dispatch($this->forProjectId);
        $this->dispatch('contributors_modified');
    }

    public function addUser(int $userId): void
    {
        Gate::authorize('manage-contributors', $this->forProject);
        $user = User::findOrFail($userId);
        $this->forProject->addContributingUser($user);
        ContributorsChanged::dispatch($this->forProjectId);
        $this->dispatch('contributors_modified');
    }

    public function removeUser(int $userId): void
    {
        Gate::authorize('manage-contributors', $this->forProject);
        $user = User::findOrFail($userId);
        $this->forProject->removeContributingUser($user);
        ContributorsChanged::dispatch($this->forProjectId);
        $this->dispatch('contributors_modified');
    }

    public function render(): mixed
    {
        $search = trim($this->search);

        $experts = Expert::query()
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.$search.'%';
                $q->where(function ($w) use ($like) {
                    $w->where('name', 'like', $like)
                        ->orWhere('job', 'like', $like)
                        ->orWhere('description', 'like', $like);
                });
            })
            ->orderBy('name')
            ->get();

        $userSearch = trim($this->userSearch);
        $users = User::query()
            ->where('id', '!=', $this->forProject->user_id)
            ->when($userSearch !== '', function ($q) use ($userSearch) {
                $like = '%'.$userSearch.'%';
                $q->where(function ($w) use ($like) {
                    $w->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like);
                });
            })
            ->orderBy('name')
            ->get();

        $canAddExpert = $this->forProject->canAddExpert();

        return view('livewire.projects.select-contributors', [
            'experts' => $experts,
            'users' => $users,
            'project' => $this->forProject,
            'hasFilters' => $this->hasActiveFilters(),
            'canAddExpert' => $canAddExpert,
            'expertLimit' => Project::MAX_CONTRIBUTING_EXPERTS,
            'limitWarning' => $this->limitWarning,
        ]);
    }
}
