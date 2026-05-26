<?php

namespace App\Livewire\Projects;

use App\Models\Expert;
use App\Models\Project;
use App\Services\Text\MemoryFormatter;
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

        // Map the memory's prompt tokens (E7/U3) back to display names so the
        // per-participant cards render with names instead of raw tokens.
        $tokenNames = [];
        if ($project !== null) {
            foreach ($project->contributingExperts() as $e) {
                $tokenNames[$e->promptId] = $e->name;
            }
            foreach ($project->users()->get()->push($project->owner)->filter()->unique('id') as $u) {
                $tokenNames[$u->promptId] = $u->name;
            }
        }

        $memory = app(MemoryFormatter::class)->parse($thoughts?->content, $tokenNames);

        // Resolve avatars for the experts mentioned inside the memory so the
        // view can show a small face next to each "Über X"-card.
        $expertAvatars = [];
        if (!empty($memory['experts']) && $project !== null) {
            $names = array_keys($memory['experts']);
            $expertAvatars = Expert::whereIn('name', $names)
                ->whereHas('projects', fn($q) => $q->whereKey($project->id))
                ->get(['id', 'name', 'avatar_url'])
                ->mapWithKeys(fn(Expert $e) => [$e->name => [
                    'id'         => $e->id,
                    'avatar_url' => $e->avatar_url,
                ]])
                ->all();
        }

        return view('livewire.projects.expert-thoughts-flyout', [
            'expert'        => $expert,
            'thoughts'      => $thoughts,
            'memory'        => $memory,
            'expertAvatars' => $expertAvatars,
        ]);
    }
}
