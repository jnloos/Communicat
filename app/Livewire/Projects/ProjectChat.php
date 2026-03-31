<?php

namespace App\Livewire\Projects;

use App\Livewire\Concerns\NeedsConfirmation;
use App\Events\ContributorsChanged;
use App\Models\Project;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

class ProjectChat extends Component
{
    use NeedsConfirmation, WithPagination;

    public int $pageSize    = 10;
    public int $incPageSize = 5;
    public bool $hasMore    = true;

    #[Locked]
    public int $projectId;
    protected Project $project;

    public function mount(Project $project): void {
        $this->projectId = $project->id;
        $this->project   = $project;
        $this->updateHasMore();
    }

    public function hydrate(): void {
        $this->project = Project::findOrFail($this->projectId);
        $this->updateHasMore();
    }

    #[On('loadMore')]
    public function loadMore(): void {
        $this->pageSize += $this->incPageSize;
        $this->updateHasMore();
    }

    public function leaveProject(): void {
        abort_if($this->project->isOwner(auth()->user()), 403);
        $this->project->removeContributingUser(auth()->user());
        ContributorsChanged::dispatch($this->projectId);
        $this->redirectRoute('project.new');
    }

    #[On('echo-private:projects.{projectId},.UserMessageSent')]
    public function onUserMessageSent(): void {
        $this->updateHasMore();
        $this->dispatch('message_generated');
    }

    #[On('echo-private:projects.{projectId},.ContributorsChanged')]
    public function onContributorsChanged(): void {
        $this->project = Project::findOrFail($this->projectId);
        $this->updateHasMore();
        $this->dispatch('contributors_modified');
    }

    private function updateHasMore(): void {
        $total         = $this->project->messages()->count();
        $this->hasMore = $this->pageSize < $total;
    }

    public function getMessagesProperty() {
        return $this->project->messages()->latest('id')
            ->take($this->pageSize)
            ->get()
            ->reverse();
    }

    #[On(['contributors_modified', 'project_edited', 'message_sent', 'message_generated'])]
    public function render(): mixed {
        return view('livewire.projects.project-chat', [
            'project'  => $this->project,
            'messages' => $this->messages,
        ]);
    }
}
