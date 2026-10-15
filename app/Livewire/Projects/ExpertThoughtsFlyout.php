<?php

namespace App\Livewire\Projects;

use App\Models\Expert;
use App\Models\Project;
use App\Models\Summary;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * An expert's short-term memory in this project: the one rolling thought the
 * Think stages overwrite each time that expert thinks. It is prose, so it is
 * shown as written.
 */
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

    public function render(): View
    {
        $expert = $this->expertId === null ? null : Expert::find($this->expertId);

        return view('livewire.projects.expert-thoughts-flyout', [
            'expert' => $expert,
            'thought' => $expert === null ? null : $this->thoughtOf($expert),
        ]);
    }

    /** Only reads: opening the flyout must never write a short-term memory. */
    private function thoughtOf(Expert $expert): ?string
    {
        $content = Summary::query()
            ->where('project_id', $this->projectId)
            ->where('expert_id', $expert->id)
            ->value('content');

        return blank($content) ? null : $content;
    }
}
