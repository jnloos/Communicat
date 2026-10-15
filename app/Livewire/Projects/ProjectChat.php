<?php

namespace App\Livewire\Projects;

use App\Events\ContributorsChanged;
use App\Livewire\Concerns\NeedsConfirmation;
use App\Models\Project;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class ProjectChat extends Component
{
    use NeedsConfirmation;

    /** How many older messages one scroll to the top adds. */
    public const PAGE_STEP = 5;

    #[Locked]
    public int $pageSize = 10;

    #[Locked]
    public int $projectId;

    public function mount(Project $project): void
    {
        $this->projectId = $project->id;
    }

    #[Computed]
    public function project(): Project
    {
        return Project::findOrFail($this->projectId);
    }

    /** The newest messages up to the page size, oldest first, with everyone they name. */
    #[Computed]
    public function messages(): Collection
    {
        return $this->project->messages()
            ->with(['expert', 'user', 'addressee'])
            ->latest('id')
            ->take($this->pageSize)
            ->get()
            ->reverse();
    }

    /** The experts, then the other people on the project, for the header's avatar group. */
    #[Computed]
    public function contributors(): Collection
    {
        return $this->project->experts()->get()
            ->concat($this->project->users()->whereKeyNot(auth()->id())->get());
    }

    #[Computed]
    public function hasMore(): bool
    {
        return $this->pageSize < $this->project->messages()->count();
    }

    #[On('loadMore')]
    public function loadMore(): void
    {
        $this->pageSize += self::PAGE_STEP;
    }

    public function leaveProject(): void
    {
        abort_if($this->project->isOwner(auth()->user()), 403);
        $this->project->removeContributingUser(auth()->user());
        ContributorsChanged::dispatch($this->projectId);
        $this->redirectRoute('project.new');
    }

    #[On('echo-private:projects.{projectId},.MessageGenerated')]
    public function onMessageGenerated(): void
    {
        $this->dispatch('message_generated', projectId: $this->projectId);
    }

    #[On('echo-private:projects.{projectId},.ContributorsChanged')]
    public function onContributorsChanged(): void
    {
        $this->dispatch('contributors_modified');
    }

    /** Re-renders with fresh data: every computed value is read anew per request. */
    #[On(['contributors_modified', 'project_edited', 'message_generated'])]
    public function refreshChatState(): void {}

    public function render(): mixed
    {
        return view('livewire.projects.project-chat');
    }
}
