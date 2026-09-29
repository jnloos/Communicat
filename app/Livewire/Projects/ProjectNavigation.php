<?php

namespace App\Livewire\Projects;

use App\Livewire\Concerns\NeedsConfirmation;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The project list in the sidebar, sorted into the signed-in user's own groups.
 *
 * Grouping is per person, not per project (see the pivot migration): everything
 * here therefore starts from auth()->user() and never from an id in the request.
 */
class ProjectNavigation extends Component
{
    use NeedsConfirmation;

    /** Which project the page is showing, so the list can mark it. */
    #[Locked]
    public ?int $currentProjectId = null;

    /** The project a new group is being created for; null while that modal is closed. */
    #[Locked]
    public ?int $newGroupForProjectId = null;

    #[Locked]
    public ?int $renamingGroupId = null;

    #[Locked]
    public ?int $renamingProjectId = null;

    public string $groupName = '';

    public string $projectTitle = '';

    public function mount(?int $currentProjectId = null): void
    {
        $this->currentProjectId = $currentProjectId;
    }

    /** A rename elsewhere (the project's own edit modal) has to reach the list. */
    #[On('project_edited')]
    public function refreshList(): void
    {
        // Rendering again is the whole job; Livewire does that after every call.
    }

    /**
     * Ticking the group the project already sits in takes it out again — there
     * is deliberately no separate "remove from group" entry.
     */
    public function toggleGroup(int $projectId, int $groupId): void
    {
        $project = $this->readableProject($projectId);
        $group = $this->ownGroup($groupId);
        $user = $this->user();

        $project->fileInGroupFor($user, $project->groupIdFor($user) === $group->id ? null : $group->id);
    }

    public function startNewGroup(int $projectId): void
    {
        $this->readableProject($projectId);

        $this->newGroupForProjectId = $projectId;
        $this->groupName = '';
        $this->resetValidation();

        Flux::modal('project-group-create')->show();
    }

    /** Creates the group and files the project into it in one step. */
    public function createGroup(): void
    {
        $project = $this->readableProject((int) $this->newGroupForProjectId);
        $this->validate($this->groupNameRules());

        $group = $this->user()->projectGroups()->create([
            'name' => trim($this->groupName),
            'position' => (int) $this->user()->projectGroups()->max('position') + 1,
        ]);

        $project->fileInGroupFor($this->user(), $group->id);

        $this->newGroupForProjectId = null;
        $this->groupName = '';
        Flux::modal('project-group-create')->close();
    }

    public function startRenamingGroup(int $groupId): void
    {
        $group = $this->ownGroup($groupId);

        $this->renamingGroupId = $group->id;
        $this->groupName = $group->name;
        $this->resetValidation();

        Flux::modal('project-group-rename')->show();
    }

    public function saveGroupName(): void
    {
        $group = $this->ownGroup((int) $this->renamingGroupId);
        $this->validate($this->groupNameRules($group->id));

        $group->update(['name' => trim($this->groupName)]);

        $this->renamingGroupId = null;
        $this->groupName = '';
        Flux::modal('project-group-rename')->close();
    }

    public function confirmDeleteGroup(int $groupId): void
    {
        $this->ownGroup($groupId);

        $this->confirmTitle = __('groups.confirm.delete_group.title');
        $this->confirmMessage = __('groups.confirm.delete_group.message');
        $this->needsConfirmation('deleteGroup', $groupId);
    }

    /** The projects stay: the pivot's foreign key is nullOnDelete. */
    public function deleteGroup(int $groupId): void
    {
        $this->ownGroup($groupId)->delete();
    }

    public function startRenamingProject(int $projectId): void
    {
        $project = $this->ownedProject($projectId);

        $this->renamingProjectId = $project->id;
        $this->projectTitle = $project->title;
        $this->resetValidation();

        Flux::modal('project-rename')->show();
    }

    public function saveProjectTitle(): void
    {
        $project = $this->ownedProject((int) $this->renamingProjectId);
        $this->validate(['projectTitle' => ['required', 'string', 'max:255']]);

        $project->title = trim($this->projectTitle);
        $project->save();

        $this->renamingProjectId = null;
        $this->projectTitle = '';
        Flux::modal('project-rename')->close();

        $this->dispatch('project_edited');
    }

    public function confirmDeleteProject(int $projectId): void
    {
        $this->ownedProject($projectId);

        $this->confirmTitle = __('groups.confirm.delete_project.title');
        $this->confirmMessage = __('groups.confirm.delete_project.message');
        $this->needsConfirmation('deleteProject', $projectId);
    }

    public function deleteProject(int $projectId): void
    {
        $project = $this->ownedProject($projectId);
        $wasOpen = $this->currentProjectId === $project->id;

        $project->delete();

        // Only the project the page is showing takes the user with it; deleting
        // one from the list while reading another must not move them.
        if ($wasOpen) {
            Cookie::forget('curr_project');
            $this->currentProjectId = null;
            $this->redirectRoute('dashboard');
        }
    }

    /**
     * Every group id from the request is resolved through this user's own
     * groups. Taking one at face value would let anybody file a project into a
     * stranger's sidebar, so a foreign id must not even be readable here.
     */
    private function ownGroup(int $groupId): ProjectGroup
    {
        $group = $this->user()->projectGroups()->find($groupId);

        // Not 403: someone else's drawer does not exist as far as this user is
        // concerned, and saying otherwise would confirm the id.
        abort_if($group === null, 404);

        return $group;
    }

    /** Filing is open to everyone who reads the project — it touches only their own row. */
    private function readableProject(int $projectId): Project
    {
        return $this->authorizedProject($projectId, 'access-project');
    }

    /** Renaming and deleting are the owner's alone, enforced here and not in the view. */
    private function ownedProject(int $projectId): Project
    {
        return $this->authorizedProject($projectId, 'manage-project');
    }

    private function authorizedProject(int $projectId, string $ability): Project
    {
        $project = Project::find($projectId);
        abort_if($project === null, 404);
        Gate::authorize($ability, $project);

        return $project;
    }

    private function user(): User
    {
        return auth()->user();
    }

    /** @return array<string, array<int, mixed>> */
    private function groupNameRules(?int $ignoreId = null): array
    {
        return [
            'groupName' => [
                'required', 'string', 'max:255',
                Rule::unique('project_groups', 'name')
                    ->where('user_id', $this->user()->id)
                    ->ignore($ignoreId),
            ],
        ];
    }

    public function render(): mixed
    {
        [$filed, $ungrouped] = $this->user()->projects()
            ->orderByDesc('projects.updated_at')
            ->get()
            ->partition(fn (Project $project) => $project->pivot->project_group_id !== null);

        $byGroup = $filed->groupBy(fn (Project $project) => (int) $project->pivot->project_group_id);
        $groups = $this->user()->projectGroups()->get();

        return view('livewire.projects.project-navigation', [
            'groups' => $groups,
            // Keyed by every group, empty ones included: an empty drawer stays visible.
            'grouped' => $groups
                ->mapWithKeys(fn (ProjectGroup $group) => [$group->id => $byGroup->get($group->id, new Collection)])
                ->all(),
            'ungrouped' => $ungrouped,
        ]);
    }
}
