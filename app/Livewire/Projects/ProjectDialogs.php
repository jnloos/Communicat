<?php

namespace App\Livewire\Projects;

use App\Actions\Projects\DeleteProject;
use App\Livewire\Concerns\NeedsConfirmation;
use App\Livewire\Forms\ProjectTitleForm;
use App\Models\Project;
use Flux\Flux;
use Livewire\Attributes\Authorize;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Renaming and deleting a project from its sidebar menu. Both are the owner's
 * alone (ProjectPolicy), enforced here and not in the view; the menu in
 * ProjectNavigation's list reaches this component by event.
 */
class ProjectDialogs extends Component
{
    use NeedsConfirmation;

    /** Which project the page is showing: deleting that one leaves the page. */
    #[Locked]
    public ?int $currentProjectId = null;

    #[Locked]
    public ?int $renamingProjectId = null;

    public ProjectTitleForm $form;

    public function mount(?int $currentProjectId = null): void
    {
        $this->currentProjectId = $currentProjectId;
    }

    #[On('rename-project')]
    #[Authorize('update', 'project')]
    public function startRenamingProject(Project $project): void
    {
        $this->renamingProjectId = $project->id;
        $this->form->fillFrom($project);
        $this->resetValidation();

        Flux::modal('project-rename')->show();
    }

    /** project_edited reaches the list and the open chat alike. */
    public function saveProjectTitle(): void
    {
        $project = Project::findOrFail($this->renamingProjectId);
        $this->authorize('update', $project);

        $project->title = $this->form->validatedTitle();
        $project->save();

        $this->renamingProjectId = null;
        $this->form->reset();
        Flux::modal('project-rename')->close();

        $this->dispatch('project_edited');
    }

    #[On('delete-project')]
    #[Authorize('delete', 'project')]
    public function confirmDeleteProject(Project $project): void
    {
        $this->confirmTitle = __('groups.confirm.delete_project.title');
        $this->confirmLabel = __('groups.confirm.delete_project.action');
        $this->confirmMessage = __('groups.confirm.delete_project.message', ['name' => $project->title]);
        $this->needsConfirmation('deleteProject', $project->id);
    }

    /** Confirmed, so called directly: takes the id and authorizes itself (see ProjectGroupDialogs::deleteGroup()). */
    public function deleteProject(int $projectId): void
    {
        $project = Project::findOrFail($projectId);
        $this->authorize('delete', $project);
        $wasOpen = $this->currentProjectId === $project->id;

        app(DeleteProject::class)->handle($project);

        // Only the project the page is showing takes the user with it; deleting
        // one from the list while reading another must not move them.
        if ($wasOpen) {
            $this->currentProjectId = null;
            $this->redirectRoute('dashboard');

            return;
        }

        $this->dispatch(ProjectNavigation::LIST_CHANGED)->to(ProjectNavigation::class);
    }

    public function render(): mixed
    {
        return view('livewire.projects.project-dialogs');
    }
}
