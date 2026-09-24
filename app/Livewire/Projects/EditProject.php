<?php

namespace App\Livewire\Projects;

use App\Discussion\Pipelines\PipelineRegistry;
use App\Discussion\Values\ModelConfig;
use App\Livewire\Concerns\NeedsConfirmation;
use App\Models\Project;
use Flux\Flux;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;

class EditProject extends Component
{
    use NeedsConfirmation;

    #[Locked]
    public int $forProjectId;

    #[Validate('required|string|max:255')]
    public string $title = '';

    #[Validate('nullable|string')]
    public string $description = '';

    public string $pipeline = '';

    public string $model = '';

    /** Pipeline and model are part of the run's identity; frozen after the first turn. */
    public bool $runStarted = false;

    public function mount(Project $project): void
    {
        $this->forProjectId = $project->id;
        $this->title = $project->title;
        $this->description = $project->description;
        $this->pipeline = $project->pipeline;
        $this->model = $project->model;
        $this->runStarted = $project->run_config !== null;
    }

    #[On('edit_project')]
    public function select(): void
    {
        Flux::modal('edit-project')->show();
    }

    protected function rules(): array
    {
        return [
            'pipeline' => ['required', Rule::in(array_keys(app(PipelineRegistry::class)->options()))],
            'model' => ['required', Rule::in(array_keys(ModelConfig::options()))],
        ];
    }

    public function save(): void
    {
        $project = Project::findOrFail($this->forProjectId);
        Gate::authorize('manage-project', $project);
        $this->validate();

        $project->title = $this->title;
        $project->description = $this->description;

        if ($project->run_config === null) {
            $project->pipeline = $this->pipeline;
            $project->model = $this->model;
        }

        $project->save();

        $this->dispatch('project_edited');
        Flux::modal('edit-project')->close();
    }

    public function delete(): void
    {
        $project = Project::findOrFail($this->forProjectId);
        Gate::authorize('manage-project', $project);
        $project->delete();
        Cookie::forget('curr_project');
        Flux::modal('edit-project')->close();
        $this->redirectRoute('dashboard');
    }

    public function render(): mixed
    {
        return view('livewire.projects.edit-project', [
            'pipelines' => app(PipelineRegistry::class)->options(),
            'models' => ModelConfig::options(),
        ]);
    }
}
