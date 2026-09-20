<?php

namespace App\Livewire\Projects;

use App\Discussion\Pipelines\PipelineRegistry;
use App\Llm\LlmFactory;
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

    #[Validate('nullable|file|max:20480')]
    public $importFile = null;

    public function mount(PipelineRegistry $pipelines): void
    {
        $this->pipeline = $pipelines->default();
        $this->model = (string) config('llm.default');
    }

    protected function rules(): array
    {
        return [
            'pipeline' => ['required', Rule::in(array_keys(app(PipelineRegistry::class)->options()))],
            'model' => ['required', Rule::in(array_keys(app(LlmFactory::class)->options()))],
        ];
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
            'models' => app(LlmFactory::class)->options(),
        ]);
    }
}
