<?php

namespace App\Livewire\Projects;

use App\Events\ContributorsChanged;
use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithoutUrlPagination;
use Livewire\WithPagination;

/**
 * The flyout that seats experts and invites users. Both change who takes part
 * in a run, so every write is the owner's alone (`ProjectPolicy::manageContributors()`).
 */
class SelectContributors extends Component
{
    use WithoutUrlPagination;
    use WithPagination;

    #[Locked]
    public int $forProjectId;

    public string $search = '';

    public string $userSearch = '';

    public ?string $limitWarning = null;

    public function mount(Project $project): void
    {
        $this->forProjectId = $project->id;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function project(): Project
    {
        return Project::findOrFail($this->forProjectId);
    }

    #[On('select_contributors')]
    public function select(): void
    {
        Flux::modal('select-contributors')->show();
    }

    public function addExpert(int $expertId): void
    {
        Gate::authorize('manageContributors', $this->project);

        if (! $this->project->canAddExpert()) {
            $this->limitWarning = trans_choice('projects.contributors.limit_warning', Project::MAX_CONTRIBUTING_EXPERTS);

            return;
        }

        $this->project->addContributingExpert(Expert::findOrFail($expertId));
        $this->contributorsChanged();
    }

    public function removeExpert(int $expertId): void
    {
        Gate::authorize('manageContributors', $this->project);
        $this->project->removeContributingExpert(Expert::findOrFail($expertId));
        $this->contributorsChanged();
    }

    public function addUser(int $userId): void
    {
        Gate::authorize('manageContributors', $this->project);
        $this->project->addContributingUser(User::findOrFail($userId));
        $this->contributorsChanged();
    }

    public function removeUser(int $userId): void
    {
        Gate::authorize('manageContributors', $this->project);
        $this->project->removeContributingUser(User::findOrFail($userId));
        $this->contributorsChanged();
    }

    private function contributorsChanged(): void
    {
        $this->limitWarning = null;
        ContributorsChanged::dispatch($this->forProjectId);
        $this->dispatch('contributors_modified');
    }

    public function render(): mixed
    {
        $project = $this->project;
        $userSearch = trim($this->userSearch);
        $seated = $project->experts()->with('personaAttributes')->get();

        $users = User::query()
            ->whereKeyNot($project->user_id)
            ->when($userSearch !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%'.$userSearch.'%')
                ->orWhere('email', 'like', '%'.$userSearch.'%')))
            ->orderBy('name')
            ->get();

        return view('livewire.projects.select-contributors', [
            'project' => $project,
            // The seated experts (at most four) stay listed in seat order whatever the page or search; the page lists the others.
            'seated' => $seated,
            'experts' => Expert::query()->with('personaAttributes')->whereKeyNot($seated->modelKeys())
                ->search($this->search)->orderBy('name')->paginate(10),
            'users' => $users,
            'invitedUserIds' => array_flip($project->users()->pluck('users.id')->all()),
            'mayManage' => Gate::allows('manageContributors', $project),
            'hasFilters' => trim($this->search) !== '',
            'canAddExpert' => $project->canAddExpert(),
            'expertLimit' => Project::MAX_CONTRIBUTING_EXPERTS,
        ]);
    }
}
