<?php

namespace App\Livewire\Projects;

use App\Discussion\Pipelines\PipelineRegistry;
use App\Discussion\Values\ModelConfig;
use App\Livewire\Concerns\NeedsConfirmation;
use App\Models\JobLog;
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

    /** Free-typed suggestions, so both are kept as text and cast when saved. */
    public string $summarizeThreshold = '';

    public string $summarizeOldest = '';

    /** Only informs the user that edits mid-run are recorded — nothing is frozen. */
    public bool $runStarted = false;

    public function mount(Project $project): void
    {
        $this->forProjectId = $project->id;
        $this->title = $project->title;
        $this->description = $project->description;
        $this->pipeline = $project->pipeline;
        $this->model = $project->model;
        $this->summarizeThreshold = (string) $project->summarizeThreshold();
        $this->summarizeOldest = (string) $project->summarizeOldest();
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
            'summarizeThreshold' => ['required', 'integer', 'min:2'],
            // Folding every pending message would leave no verbatim history at all.
            'summarizeOldest' => ['required', 'integer', 'min:1', 'lt:summarizeThreshold'],
        ];
    }

    public function save(): void
    {
        $project = Project::findOrFail($this->forProjectId);
        Gate::authorize('manage-project', $project);
        $this->validate();

        $this->noteRunConfigChanges($project);

        $project->title = $this->title;
        $project->description = $this->description;
        $project->pipeline = $this->pipeline;
        $project->model = $this->model;
        $project->summarize_threshold = (int) $this->summarizeThreshold;
        $project->summarize_oldest = (int) $this->summarizeOldest;
        $project->save();

        $this->dispatch('project_edited');
        Flux::modal('edit-project')->close();
    }

    /**
     * run_config describes the run as of its first turn. Pipeline and model stay
     * editable, so a change mid-run would make that snapshot claim a condition
     * that no longer holds: append one entry per change instead, naming the turn
     * it took effect after. Called before the new values are assigned.
     */
    private function noteRunConfigChanges(Project $project): void
    {
        if ($project->run_config === null) {
            return;
        }

        $wanted = ['pipeline' => $this->pipeline, 'model' => $this->model];
        $changed = array_filter($wanted, fn (string $new, string $field) => $project->{$field} !== $new, ARRAY_FILTER_USE_BOTH);

        if ($changed === []) {
            return;
        }

        $afterTurn = (int) JobLog::where('project_id', $project->id)->max('turn_index');
        $snapshot = $project->run_config;

        foreach ($changed as $field => $new) {
            $snapshot['changes'][] = [
                'after_turn' => $afterTurn,
                'field' => $field,
                'from' => $project->{$field},
                'to' => $new,
            ];
        }

        $project->run_config = $snapshot;
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
