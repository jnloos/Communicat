<?php

namespace App\Livewire\Projects;

use App\Discussion\Pipelines\PipelineRegistry;
use App\Discussion\Values\ModelConfig;
use App\Models\Project;
use App\Services\ProjectTransfer\ProjectImporter;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

class CreateProject extends Component
{
    use WithFileUploads;

    #[Validate('required|string|max:255')]
    public string $title = '';

    #[Validate('required|string')]
    public string $description = '';

    public string $pipeline = '';

    public string $model = '';

    /** UI state only: the provider filters the model dropdown and is never stored. */
    public string $provider = '';

    /** Free-typed suggestions, so both are kept as text and cast when saved. */
    public string $summarizeThreshold = '';

    public string $summarizeOldest = '';

    #[Validate('nullable|file|max:20480')]
    public $importFile = null;

    public function mount(PipelineRegistry $pipelines): void
    {
        $this->pipeline = $pipelines->default();
        $this->model = (string) config('ai.default_model');
        $this->provider = ModelConfig::fromConfig($this->model)->provider;
        $this->summarizeThreshold = (string) config('discussion.summarize_threshold');
        $this->summarizeOldest = (string) config('discussion.summarize_oldest');
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
        $this->validate();

        $project = new Project;
        $project->user_id = auth()->id();
        $project->title = $this->title;
        $project->description = $this->description;
        $project->pipeline = $this->pipeline;
        $project->model = $this->model;
        $project->summarize_threshold = (int) $this->summarizeThreshold;
        $project->summarize_oldest = (int) $this->summarizeOldest;
        $project->save();

        $project->addContributingUser(auth()->user());
        $project->addMessage(view('components.projects.welcome-message', ['project' => $project])->render());

        $this->redirect(route('project.show', $project), navigate: true);
    }

    /** Alternative to save(): create a full project copy from an uploaded JSON export. */
    public function createFromFile(): void
    {
        $this->validate(['importFile' => 'required|file|max:20480']);

        $data = json_decode(file_get_contents($this->importFile->getRealPath()), true);
        if (! is_array($data) || ! isset($data['project'])) {
            $this->addError('importFile', __('projects.import.invalid_file'));

            return;
        }

        $result = app(ProjectImporter::class)->import($data, auth()->user());

        if (! empty($result['missing_experts'])) {
            session()->flash('import_warning', __('projects.import.missing_experts'));
        }

        $this->redirect(route('project.show', $result['project']), navigate: true);
    }

    public function render(): mixed
    {
        return view('livewire.projects.create-project', [
            'pipelines' => app(PipelineRegistry::class)->options(),
            'providers' => ModelConfig::providers(),
            'models' => ModelConfig::optionsFor($this->provider),
        ]);
    }
}
