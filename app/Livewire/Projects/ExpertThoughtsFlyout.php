<?php

namespace App\Livewire\Projects;

use App\Models\Expert;
use App\Models\Project;
use Flux\Flux;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class ExpertThoughtsFlyout extends Component
{
    #[Locked]
    public int $projectId;

    public ?int $expertId = null;

    public function mount(Project $project): void
    {
        $this->projectId = $project->id;
    }

    #[On('open-expert-thoughts')]
    public function openFor(int $expertId): void
    {
        $this->expertId = $expertId;
        Flux::modal('expert-thoughts-flyout')->show();
    }

    public function render(): mixed
    {
        $expert   = $this->expertId ? Expert::find($this->expertId) : null;
        $project  = Project::find($this->projectId);
        $thoughts = $expert && $project ? $expert->thoughtsAbout($project) : null;

        return view('livewire.projects.expert-thoughts-flyout', [
            'expert'   => $expert,
            'thoughts' => $thoughts,
        ]);
    }
}
