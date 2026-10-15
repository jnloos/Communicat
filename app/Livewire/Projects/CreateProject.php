<?php

namespace App\Livewire\Projects;

use App\Actions\Projects\OpenProject;
use App\Discussion\Pipelines\PipelineRegistry;
use App\Discussion\Values\ModelConfig;
use App\Livewire\Forms\ProjectForm;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\TagValue;
use Livewire\Component;

class CreateProject extends Component
{
    public ProjectForm $form;

    /** @var array<int|string, int|string|null> bound by x-tags.fields; category id => value id */
    public array $tagSelection = [];

    public function mount(PipelineRegistry $pipelines): void
    {
        $this->form->fillDefaults($pipelines);
    }

    public function save(OpenProject $openProject): void
    {
        $this->form->validate();

        $project = $openProject->handle(auth()->user(), $this->form->attributes(), tags: $this->tagSelection);

        $this->redirect(route('project.show', $project), navigate: true);
    }

    public function render(): mixed
    {
        return view('livewire.projects.create-project', [
            'pipelines' => app(PipelineRegistry::class)->options(),
            'providers' => ModelConfig::providers(),
            'models' => ModelConfig::optionsFor($this->form->provider),
            'tagCategories' => TagCategory::ofScope(TagScope::Project)->with('values')->get(),
            'chosenTags' => TagValue::chosenIn(TagScope::Project, $this->tagSelection),
        ]);
    }
}
