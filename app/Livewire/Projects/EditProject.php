<?php

namespace App\Livewire\Projects;

use App\Livewire\Concerns\NeedsConfirmation;
use App\Models\Project;
use App\Services\ProjectImporter;
use Flux\Flux;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

class EditProject extends Component
{
    use NeedsConfirmation;
    use WithFileUploads;

    #[Locked]
    public int $forProjectId;

    #[Validate('nullable|file|max:20480')]
    public $importFile = null;

    #[Validate('required|string|max:255')]
    public string $title = '';

    #[Validate('nullable|string')]
    public string $description = '';

    #[Validate('required|in:5,10,20')]
    public int $frequency = 10;

    public function mount(Project $project): void {
        $this->forProjectId = $project->id;
        $this->title        = $project->title;
        $this->description  = $project->description;
        $this->frequency    = $project->settings['summary_frequency'] ?? 10;
    }

    #[On('edit_project')]
    public function select(): void {
        Flux::modal('edit-project')->show();
    }

    public function save(): void {
        $project = Project::findOrFail($this->forProjectId);
        Gate::authorize('manage-project', $project);
        $this->validate();

        $project->title       = $this->title;
        $project->description = $this->description;
        $project->settings    = ['summary_frequency' => $this->frequency];
        $project->save();

        $this->dispatch('project_edited');
        Flux::modal('edit-project')->close();
    }

    /** Import a JSON export as a new project owned by the current user. */
    public function import(): void {
        $this->validate(['importFile' => 'required|file|max:20480']);

        $data = json_decode(file_get_contents($this->importFile->getRealPath()), true);
        if (! is_array($data) || ! isset($data['project'])) {
            $this->addError('importFile', __('Invalid export file.'));
            return;
        }

        $result = app(ProjectImporter::class)->import($data, auth()->user());

        $this->reset('importFile');
        Cookie::queue('curr_project', $result['project']->id);
        Flux::modal('edit-project')->close();

        if (! empty($result['missing_experts'])) {
            session()->flash('import_warning', __('Some experts no longer exist and were skipped.'));
        }

        $this->redirectRoute('dashboard');
    }

    public function delete(): void {
        $project = Project::findOrFail($this->forProjectId);
        Gate::authorize('manage-project', $project);
        $project->delete();
        Cookie::forget('curr_project');
        Flux::modal('edit-project')->close();
        $this->redirectRoute('dashboard');
    }

    public function render(): mixed {
        return view('livewire.projects.edit-project');
    }
}
