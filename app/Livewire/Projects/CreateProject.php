<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use App\Services\ProjectTransfer\ProjectImporter;
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

    #[Validate('required|in:5,10,20')]
    public int $frequency = 10;

    #[Validate('nullable|file|max:20480')]
    public $importFile = null;

    public function save(): void {
        $this->validate();

        $project = new Project();
        $project->title       = $this->title;
        $project->description = $this->description;
        $project->settings    = ['summary_frequency' => $this->frequency];
        $project->save();

        $this->redirect(route('project.show', $project), navigate: true);
    }

    /** Alternative to save(): create a full project copy from an uploaded JSON export. */
    public function createFromFile(): void {
        $this->validate(['importFile' => 'required|file|max:20480']);

        $data = json_decode(file_get_contents($this->importFile->getRealPath()), true);
        if (! is_array($data) || ! isset($data['project'])) {
            $this->addError('importFile', __('Invalid export file.'));
            return;
        }

        $result = app(ProjectImporter::class)->import($data, auth()->user());

        if (! empty($result['missing_experts'])) {
            session()->flash('import_warning', __('Some experts no longer exist and were skipped.'));
        }

        $this->redirect(route('project.show', $result['project']), navigate: true);
    }

    public function render(): mixed {
        return view('livewire.projects.create-project');
    }
}
