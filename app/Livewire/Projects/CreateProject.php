<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use Livewire\Attributes\Validate;
use Livewire\Component;

class CreateProject extends Component
{
    #[Validate('required|string|max:255')]
    public string $title = '';

    #[Validate('required|string')]
    public string $description = '';

    #[Validate('required|in:5,10,20')]
    public int $frequency = 10;

    public function save(): void {
        $this->validate();

        $project = new Project();
        $project->title       = $this->title;
        $project->description = $this->description;
        $project->settings    = ['summary_frequency' => $this->frequency];
        $project->save();

        $this->redirect(route('project.show', $project), navigate: true);
    }

    public function render(): mixed {
        return view('livewire.projects.create-project');
    }
}
