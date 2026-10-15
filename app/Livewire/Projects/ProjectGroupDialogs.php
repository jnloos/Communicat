<?php

namespace App\Livewire\Projects;

use App\Livewire\Concerns\NeedsConfirmation;
use App\Livewire\Forms\GroupNameForm;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Models\User;
use Flux\Flux;
use Livewire\Attributes\Authorize;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Creating, renaming and deleting the signed-in user's sidebar groups.
 *
 * The menus that start these live in ProjectNavigation's list and reach this
 * component by event; every change is announced back so the list renders again.
 * Event parameters are bound and authorized like a wire:click's, so someone
 * else's group still answers 404 (ProjectGroupPolicy).
 */
class ProjectGroupDialogs extends Component
{
    use NeedsConfirmation;

    /** The project a new group is being created for; null while that modal is closed. */
    #[Locked]
    public ?int $newGroupForProjectId = null;

    #[Locked]
    public ?int $renamingGroupId = null;

    public GroupNameForm $form;

    /** Filing is open to everyone who reads the project — it touches only their own row. */
    #[On('new-group')]
    #[Authorize('view', 'project')]
    public function startNewGroup(Project $project): void
    {
        $this->newGroupForProjectId = $project->id;
        $this->openForm('project-group-create');
    }

    /** Creates the group and files the project into it in one step. */
    public function createGroup(): void
    {
        $project = Project::findOrFail($this->newGroupForProjectId);
        $this->authorize('view', $project);

        $group = $this->user()->projectGroups()->create([
            'name' => $this->form->validatedName(),
            'position' => (int) $this->user()->projectGroups()->max('position') + 1,
        ]);
        $project->fileInGroupFor($this->user(), $group->id);

        $this->newGroupForProjectId = null;
        $this->closeForm('project-group-create');
        $this->announceListChanged();
    }

    #[On('rename-group')]
    #[Authorize('update', 'group')]
    public function startRenamingGroup(ProjectGroup $group): void
    {
        $this->renamingGroupId = $group->id;
        $this->openForm('project-group-rename', $group);
    }

    public function saveGroupName(): void
    {
        $group = ProjectGroup::findOrFail($this->renamingGroupId);
        $this->authorize('update', $group);

        $group->update(['name' => $this->form->validatedName($group)]);

        $this->renamingGroupId = null;
        $this->closeForm('project-group-rename');
        $this->announceListChanged();
    }

    #[On('delete-group')]
    #[Authorize('delete', 'group')]
    public function confirmDeleteGroup(ProjectGroup $group): void
    {
        $this->confirmTitle = __('groups.confirm.delete_group.title');
        $this->confirmMessage = __('groups.confirm.delete_group.message');
        $this->needsConfirmation('deleteGroup', $group->id);
    }

    /**
     * The projects stay: the pivot's foreign key is nullOnDelete. A confirmed
     * action is called directly, past Livewire's attributes and bindings, so it
     * takes the id and authorizes itself.
     */
    public function deleteGroup(int $groupId): void
    {
        $group = ProjectGroup::findOrFail($groupId);
        $this->authorize('delete', $group);
        $group->delete();

        $this->announceListChanged();
    }

    public function render(): mixed
    {
        return view('livewire.projects.project-group-dialogs');
    }

    /** Empty for a new group, the current name for a rename. */
    private function openForm(string $modal, ?ProjectGroup $group = null): void
    {
        $this->form->reset();
        if ($group !== null) {
            $this->form->fillFrom($group);
        }
        $this->resetValidation();

        Flux::modal($modal)->show();
    }

    private function closeForm(string $modal): void
    {
        $this->form->reset();
        Flux::modal($modal)->close();
    }

    private function announceListChanged(): void
    {
        $this->dispatch(ProjectNavigation::LIST_CHANGED)->to(ProjectNavigation::class);
    }

    private function user(): User
    {
        return auth()->user();
    }
}
