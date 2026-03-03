<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

class ProjectChat extends Component
{
    use WithPagination;

    public int $pageSize = 10;
    public int $incPageSize = 5;
    public bool $hasMore = true;

    #[Locked]
    public int $projectId;
    protected Project $project;

    public function mount(Project $project): void {
        $this->projectId = $project->id;
        $this->project   = $project;
        $this->updateHasMore();
    }

    public function hydrate(): void {
        $this->project = Project::with('contributors')->findOrFail($this->projectId);
        $this->updateHasMore();
    }

    #[On('loadMore')]
    public function loadMore(): void {
        $this->pageSize += $this->incPageSize;
        $this->updateHasMore();
    }

    private function updateHasMore(): void {
        $total = $this->project->messages()->count();
        $this->hasMore = $this->pageSize < $total;
    }

    public function getMessagesProperty() {
        return $this->project->messages()->latest('id')
            ->take($this->pageSize)
            ->get()
            ->reverse();
    }

    #[On(['contributors_modified', 'project_edited', 'message_sent', 'ticked'])]
    public function render(): mixed {
        return view('livewire.projects.project-chat', [
            'project'  => $this->project,
            'messages' => $this->messages,
        ]);
    }
}
