<?php

namespace App\Livewire\Projects;

use App\Discussion\Values\TagFilter;
use App\Discussion\Values\TagFilterMode;
use App\Livewire\Concerns\FiltersByTags;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\User;
use Illuminate\Support\Collection;
use Livewire\Attributes\Authorize;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Session;
use Livewire\Component;

/**
 * The project list in the sidebar, sorted into the signed-in user's own groups
 * and narrowed by the tag filter.
 *
 * Grouping is per person, not per project (see the pivot migration): every
 * project and group id from the request is bound to its model and checked
 * against ProjectPolicy and ProjectGroupPolicy before anything is written, and
 * someone else's group answers 404, as if it did not exist.
 *
 * The dialogs behind the menus live in ProjectGroupDialogs and ProjectDialogs;
 * they announce a change with LIST_CHANGED, and a rename from the project's
 * own edit modal arrives as project_edited. Both only render the list again.
 */
#[On(['project_edited', self::LIST_CHANGED])]
class ProjectNavigation extends Component
{
    public const LIST_CHANGED = 'project-list-changed';

    use FiltersByTags;

    /** Which project the page is showing, so the list can mark it. */
    #[Locked]
    public ?int $currentProjectId = null;

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

    private function user(): User
    {
        return auth()->user();
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
