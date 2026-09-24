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

    /** UI state only: the provider filters the model dropdown and is never stored. */
    public string $provider = '';

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
        $this->provider = ModelConfig::fromConfig($project->model)->provider;
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
            'provider' => ['required', Rule::in(array_keys(ModelConfig::providers()))],
            // Against the provider's own models, so a forged request cannot pair
            // a model with a provider it does not belong to.
            'model' => ['required', Rule::in(array_keys(ModelConfig::optionsFor($this->provider)))],
            'summarizeThreshold' => ['required', 'integer', 'min:2'],
            // Folding every pending message would leave no verbatim history at all.
            'summarizeOldest' => ['required', 'integer', 'min:1', 'lt:summarizeThreshold'],
        ];
    }

    /** A new provider invalidates the chosen model: move to its first one. */
    public function updatedProvider(): void
    {
        $this->model = (string) array_key_first(ModelConfig::optionsFor($this->provider));
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
     * run_config describes the run as of its first turn. Pipeline, model and the two
     * summarization numbers all stay editable, so a change mid-run would make that
     * snapshot claim a condition that no longer holds: append one entry per change
     * instead, naming the turn it took effect after. Called before the new values
     * are assigned.
     *
     * The summarization numbers are the ones that truly depend on this entry. The
     * model leaves a trail in prompt_logs.config on every single call and the
     * mechanism in job_logs.selection on every turn, so a switch of either can be
     * reconstructed from the data alone — these two appear nowhere else.
     *
     * The numbers are compared as effective values (the accessors fall back to the
     * configured default), so filling a null column with the value it already had
     * is not a change of condition and records nothing.
     *
     * after_turn is the highest turn index that exists when the change is saved. A
     * turn still running may pick the new values up, so read it as "from this turn
     * onwards", not "strictly after it".
     */
    private function noteRunConfigChanges(Project $project): void
    {
        if ($project->run_config === null) {
            return;
        }

        $fields = [
            'pipeline' => [$project->pipeline, $this->pipeline],
            'model' => [$project->model, $this->model],
            'summarize_threshold' => [$project->summarizeThreshold(), (int) $this->summarizeThreshold],
            'summarize_oldest' => [$project->summarizeOldest(), (int) $this->summarizeOldest],
        ];

        $changed = array_filter($fields, fn (array $pair) => $pair[0] !== $pair[1]);

        if ($changed === []) {
            return;
        }

        $afterTurn = (int) JobLog::where('project_id', $project->id)->max('turn_index');
        $snapshot = $project->run_config;

        foreach ($changed as $field => [$from, $to]) {
            $snapshot['changes'][] = [
                'after_turn' => $afterTurn,
                'field' => $field,
                'from' => $from,
                'to' => $to,
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
            'providers' => ModelConfig::providers(),
            'models' => ModelConfig::optionsFor($this->provider),
        ]);
    }
}
