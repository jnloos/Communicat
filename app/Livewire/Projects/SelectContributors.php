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

/**
 * The flyout that seats experts and invites users. Both change who takes part
 * in a run, so every write is the owner's alone (`manage-contributors`).
 */
class SelectContributors extends Component
{
    #[Locked]
    public int $forProjectId;

    public string $search = '';

    public string $userSearch = '';

    public ?string $limitWarning = null;

    public function mount(Project $project): void
    {
        $this->forProjectId = $project->id;
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
        Gate::authorize('manage-contributors', $this->project);

        if (! $this->project->canAddExpert()) {
            $this->limitWarning = trans_choice('projects.contributors.limit_warning', Project::MAX_CONTRIBUTING_EXPERTS);

            return;
        }

        $this->project->addContributingExpert(Expert::findOrFail($expertId));
        $this->contributorsChanged();
    }

    public function removeExpert(int $expertId): void
    {
        Gate::authorize('manage-contributors', $this->project);
        $this->project->removeContributingExpert(Expert::findOrFail($expertId));
        $this->contributorsChanged();
    }

    public function addUser(int $userId): void
    {
        Gate::authorize('manage-contributors', $this->project);
        $this->project->addContributingUser(User::findOrFail($userId));
        $this->contributorsChanged();
    }

    public function removeUser(int $userId): void
    {
        Gate::authorize('manage-contributors', $this->project);
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

        $users = User::query()
            ->whereKeyNot($project->user_id)
            ->when($userSearch !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%'.$userSearch.'%')
                ->orWhere('email', 'like', '%'.$userSearch.'%')))
            ->orderBy('name')
            ->get();

        return view('livewire.projects.select-contributors', [
            'project' => $project,
            'experts' => Expert::query()->search($this->search)->orderBy('name')->get(),
            'users' => $users,
            'seatedExpertIds' => array_flip($project->experts()->pluck('experts.id')->all()),
            'invitedUserIds' => array_flip($project->users()->pluck('users.id')->all()),
            'mayManage' => Gate::allows('manage-contributors', $project),
            'hasFilters' => trim($this->search) !== '',
            'canAddExpert' => $project->canAddExpert(),
            'expertLimit' => Project::MAX_CONTRIBUTING_EXPERTS,
        ]);
    }
}
