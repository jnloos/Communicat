<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use App\Services\MultiplePrompting;
use App\Services\SinglePrompting;
use Livewire\Attributes\Validate;
use Livewire\Component;

class CreateProject extends Component
{
    #[Validate('required|string|max:255')]
    public string $title = '';

    #[Validate('required|string')]
    public string $description = '';

    #[Validate('required|in:5,10,20')]
    public int $frequency= 10;

    #[Validate('required|in:multiple,single')]
    public string $strategy = 'multiple';

    public function save(): void {
        $this->validate();

        $project = new Project();
        $project->title = $this->title;
        $project->description = $this->description;
        $project->summary_frequency = $this->frequency;
        match($this->strategy) {
            'single' => $project->prompting_strategy = SinglePrompting::class,
            'multiple' => $project->prompting_strategy = MultiplePrompting::class,
        };
        $project->save();

        $this->redirect(route('project.show', $project), navigate: true);
    }

    public function render(): mixed {
        return view('livewire.projects.create-project');
    }
}
