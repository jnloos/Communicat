<?php

namespace App\Livewire\Forms;

use App\Models\Project;
use Livewire\Form;

/** The sidebar's rename of a project: the title alone, under ProjectForm's rule. */
class ProjectTitleForm extends Form
{
    public string $title = '';

    public function fillFrom(Project $project): void
    {
        $this->title = $project->title;
    }

    /** Validates and returns the title as it is stored, trimmed. */
    public function validatedTitle(): string
    {
        $this->validate();

        return trim($this->title);
    }

    protected function rules(): array
    {
        return ['title' => ProjectForm::TITLE_RULES];
    }
}
