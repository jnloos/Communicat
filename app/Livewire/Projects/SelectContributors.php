<?php

namespace App\Livewire\Projects;

use App\Models\Expert;
use App\Models\Project;
use Flux\Flux;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class SelectContributors extends Component
{
    #[Locked]
    public int $forProjectId;
    protected Project $forProject;

    public function mount(Project $project): void {
        $this->forProjectId = $project->id;
        $this->forProject = $project;
    }

    public function hydrate(): void {
        $this->refreshProject();
    }

    public function refreshProject(): void {
        $this->forProject = Project::find($this->forProjectId);
    }

    #[On('select_contributors')]
    public function select(): void {
        Flux::modal('select-contributors')->show();
    }

    public function addExpert(int $expertId): void {
        $expert = Expert::findOrFail($expertId);
        $this->forProject->addContributingExpert($expert);
        $this->dispatch('contributors_modified');
    }

    public function removeExpert(int $expertId): void {
        $expert = Expert::findOrFail($expertId);
        $this->forProject->removeContributingExpert($expert);
        $this->dispatch('contributors_modified');
    }

    public function render(): mixed {
        return view('livewire.projects.select-contributors', [
            'experts' => Expert::all(),
            'project' => $this->forProject,
        ]);
    }
}
