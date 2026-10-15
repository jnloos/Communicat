<?php

namespace App\Livewire\Projects;

use App\Discussion\Values\TagFilter;
use App\Discussion\Values\TagFilterMode;
use App\Livewire\Concerns\FiltersByTags;
use App\Livewire\Concerns\NeedsConfirmation;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Authorize;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Session;
use Livewire\Component;

/**
 * The project list in the sidebar, sorted into the signed-in user's own groups.
 *
 * Grouping is per person, not per project (see the pivot migration): every
 * project and group id from the request is bound to its model and checked
 * against ProjectPolicy and ProjectGroupPolicy before anything is written, and
 * someone else's group answers 404, as if it did not exist.
 */
class ProjectNavigation extends Component
{
    use FiltersByTags;
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

    /**
     * Project tag values the list is narrowed to, combined by $tagFilterMode.
     * Bound to the menu's checkboxes (one flux:menu.checkbox.group over every
     * category). Personal and kept in the session, so it survives navigating
     * from project to project.
     *
     * @var list<int|string>
     */
    #[Session]
    public array $tagFilter = [];

    /**
     * Cut: a project matches every picked category (any picked value within
     * one). Merge: any one picked value is enough.
     */
    #[Session]
    public string $tagFilterMode = TagFilterMode::Cut->value;

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
    #[Authorize('view', 'project')]
    #[Authorize('update', 'group')]
    public function toggleGroup(Project $project, ProjectGroup $group): void
    {
        $user = $this->user();

        $project->fileInGroupFor($user, $project->groupIdFor($user) === $group->id ? null : $group->id);
    }

    /** Filing is open to everyone who reads the project — it touches only their own row. */
    #[Authorize('view', 'project')]
    public function startNewGroup(Project $project): void
    {
        $this->newGroupForProjectId = $project->id;
        $this->groupName = '';
        $this->resetValidation();

        Flux::modal('project-group-create')->show();
    }

    /** Creates the group and files the project into it in one step. */
    public function createGroup(): void
    {
        $project = Project::findOrFail($this->newGroupForProjectId);
        $this->authorize('view', $project);
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

    #[Authorize('update', 'group')]
    public function startRenamingGroup(ProjectGroup $group): void
    {
        $this->renamingGroupId = $group->id;
        $this->groupName = $group->name;
        $this->resetValidation();

        Flux::modal('project-group-rename')->show();
    }

    public function saveGroupName(): void
    {
        $group = ProjectGroup::findOrFail($this->renamingGroupId);
        $this->authorize('update', $group);
        $this->validate($this->groupNameRules($group->id));

        $group->update(['name' => trim($this->groupName)]);

        $this->renamingGroupId = null;
        $this->groupName = '';
        Flux::modal('project-group-rename')->close();
    }

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
    }

    /** Renaming and deleting are the owner's alone, enforced here and not in the view. */
    #[Authorize('update', 'project')]
    public function startRenamingProject(Project $project): void
    {
        $this->renamingProjectId = $project->id;
        $this->projectTitle = $project->title;
        $this->resetValidation();

        Flux::modal('project-rename')->show();
    }

    public function saveProjectTitle(): void
    {
        $project = Project::findOrFail($this->renamingProjectId);
        $this->authorize('update', $project);
        $this->validate(['projectTitle' => ['required', 'string', 'max:255']]);

        $project->title = trim($this->projectTitle);
        $project->save();

        $this->renamingProjectId = null;
        $this->projectTitle = '';
        Flux::modal('project-rename')->close();

        $this->dispatch('project_edited');
    }

    #[Authorize('delete', 'project')]
    public function confirmDeleteProject(Project $project): void
    {
        $this->confirmTitle = __('groups.confirm.delete_project.title');
        $this->confirmMessage = __('groups.confirm.delete_project.message');
        $this->needsConfirmation('deleteProject', $project->id);
    }

    /** Confirmed, so called directly: takes the id and authorizes itself (see deleteGroup()). */
    public function deleteProject(int $projectId): void
    {
        $project = Project::findOrFail($projectId);
        $this->authorize('delete', $project);
        $wasOpen = $this->currentProjectId === $project->id;

        $project->delete();

        // Only the project the page is showing takes the user with it; deleting
        // one from the list while reading another must not move them.
        if ($wasOpen) {
            Cookie::expire('curr_project');
            $this->currentProjectId = null;
            $this->redirectRoute('dashboard');
        }
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
        $tagCategories = TagCategory::ofScope(TagScope::Project)->with('values')->get();
        $filter = $this->normaliseFilterState($tagCategories);
        $outsideFilterProjectId = $this->openProjectOutside($filter);

        // The open project always stays in the list, even when it does not match:
        // the row marking where the user is must not vanish under a filter.
        [$filed, $ungrouped] = $this->user()->projects()
            ->where(fn ($query) => $this->applyTagFilter($query, $filter)
                ->when($outsideFilterProjectId !== null, fn ($query) => $query->orWhere('projects.id', $outsideFilterProjectId)))
            ->orderByDesc('projects.updated_at')
            ->get()
            ->partition(fn (Project $project) => $project->pivot->project_group_id !== null);

        $byGroup = $filed->groupBy(fn (Project $project) => (int) $project->pivot->project_group_id);
        $groups = $this->user()->projectGroups()->get();

        // Unfiltered, an empty drawer stays visible so it can be filled; while a
        // filter is on, a drawer without a match is only noise.
        if (! $filter->isEmpty()) {
            $groups = $groups->filter(fn (ProjectGroup $group) => $byGroup->has($group->id))->values();
        }

        // The match count only serves the no-match message, which exists once
        // there are categories to filter by; without one it would be a wasted query.
        $hasCategories = $tagCategories->isNotEmpty();

        return view('livewire.projects.project-navigation', [
            'groups' => $groups,
            'grouped' => $groups
                ->mapWithKeys(fn (ProjectGroup $group) => [$group->id => $byGroup->get($group->id, new Collection)])
                ->all(),
            'ungrouped' => $ungrouped,
            'tagCategories' => $tagCategories,
            'activeFilterCount' => count($filter->valueIds()),
            'tagFilterMode' => $this->tagFilterMode,
            'matchCount' => $hasCategories ? $this->applyTagFilter($this->user()->projects(), $filter)->count() : null,
            'outsideFilterProjectId' => $outsideFilterProjectId,
        ]);
    }

    /**
     * The open project, if it is one of the user's own and the filter hides it;
     * null otherwise.
     */
    private function openProjectOutside(TagFilter $filter): ?int
    {
        if ($filter->isEmpty() || $this->currentProjectId === null) {
            return null;
        }

        $own = $this->user()->projects()->whereKey($this->currentProjectId);

        if (! (clone $own)->exists() || $this->applyTagFilter($own, $filter)->exists()) {
            return null;
        }

        return $this->currentProjectId;
    }
}
