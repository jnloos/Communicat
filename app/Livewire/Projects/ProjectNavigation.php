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
use Illuminate\Contracts\Pagination\Paginator;
use Livewire\Attributes\Authorize;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Session;
use Livewire\Component;
use Livewire\WithoutUrlPagination;
use Livewire\WithPagination;

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

    /** Rows per drawer and in the ungrouped list; a study wave holds thousands of runs. */
    public const PER_PAGE = 10;

    use FiltersByTags;
    use WithoutUrlPagination;
    use WithPagination;

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

    /**
     * Where render() pinned the open project on top of a page it is not on: a
     * group id, 'ungrouped', or null. Per request only, so private.
     */
    private int|string|null $pinnedIn = null;

    public function mount(?int $currentProjectId = null): void
    {
        $this->currentProjectId = $currentProjectId;
    }

    /**
     * Ticking the group the project already sits in takes it out again. There
     * is deliberately no separate "remove from group" entry.
     */
    #[Authorize('view', 'project')]
    #[Authorize('update', 'group')]
    public function toggleGroup(Project $project, ProjectGroup $group): void
    {
        $user = $this->user();

        $project->fileInGroupFor($user, $project->groupIdFor($user) === $group->id ? null : $group->id);
    }

    /**
     * wire:sort hands over the dragged group and its new 0-based index.
     *
     * The index counts the groups the sidebar shows, and a tag filter hides
     * drawers, so it would not point into the user's full list. Groups are
     * therefore sorted only while no filter is on: the view leaves wire:sort
     * and the menu's move items out, and this refuses a request that comes
     * anyway (a stale tab) without changing anything.
     */
    #[Authorize('update', 'group')]
    public function sortGroup(ProjectGroup $group, int $position): void
    {
        if ($this->sortingAllowed()) {
            $group->moveTo($position);
        }
    }

    /** @param  int  $steps  -1 moves the group one place up, 1 one place down; same rule as sortGroup() */
    #[Authorize('update', 'group')]
    public function moveGroup(ProjectGroup $group, int $steps): void
    {
        if ($this->sortingAllowed()) {
            $group->moveBy($steps);
        }
    }

    private function sortingAllowed(): bool
    {
        return $this->activeTagFilter(TagCategory::ofScope(TagScope::Project)->with('values')->get())->isEmpty();
    }

    private function user(): User
    {
        return auth()->user();
    }

    public function render(): mixed
    {
        $user = $this->user();
        $this->pinnedIn = null;
        $tagCategories = TagCategory::ofScope(TagScope::Project)->with('values')->get();
        $filter = $this->normaliseFilterState($tagCategories);
        $open = $this->currentProjectId === null ? null : $user->projects()->whereKey($this->currentProjectId)->first();
        $outsideFilterProjectId = $this->openProjectOutside($open, $filter);
        $openGroupId = $open === null ? null : $this->groupIdOf($open);

        $groups = $user->projectGroups()->get();

        // One grouped COUNT gives every list its page count, the filter's match
        // count and, while a filter is on, which drawers still have a match
        // (unfiltered, an empty drawer stays visible so it can be filled).
        $counts = $this->applyTagFilter($user->projects(), $filter)->toBase()
            ->select('project_contributors.project_group_id')->selectRaw('count(*) as matches')
            ->groupBy('project_contributors.project_group_id')
            ->pluck('matches', 'project_group_id');

        if (! $filter->isEmpty()) {
            $groups = $groups->filter(fn (ProjectGroup $group) => $counts->has($group->id) || $group->id === $openGroupId)->values();
        }

        $grouped = $groups->mapWithKeys(fn (ProjectGroup $group) => [
            $group->id => $this->page($filter, $group->id, $open),
        ])->all();
        $ungrouped = $this->page($filter, null, $open);

        return view('livewire.projects.project-navigation', [
            'groups' => $groups,
            'sortable' => $filter->isEmpty(),
            'grouped' => $grouped,
            'ungrouped' => $ungrouped,
            'pages' => $groups->mapWithKeys(fn (ProjectGroup $group) => [$group->id => $this->lastPage($counts->get($group->id, 0))])->all(),
            // pluck() keys the ungrouped (NULL) row as ''.
            'ungroupedPages' => $this->lastPage($counts->get('', 0)),
            'pinnedIn' => $this->pinnedIn,
            'tagCategories' => $tagCategories,
            'activeFilterCount' => count($filter->valueIds()),
            'tagFilterMode' => $this->tagFilterMode,
            // Only the no-match message reads it, and that needs categories to filter by.
            'matchCount' => $tagCategories->isNotEmpty() ? (int) $counts->sum() : null,
            'outsideFilterProjectId' => $outsideFilterProjectId,
        ]);
    }

    /**
     * One page of a drawer ($groupId) or of the ungrouped list (null), newest first.
     *
     * The open project always stays in its list, even when it does not match the
     * filter or sits on another page: it is then pinned on top of the page shown.
     */
    private function page(TagFilter $filter, ?int $groupId, ?Project $open): Paginator
    {
        $query = $this->user()->projects();
        $query = $groupId === null ? $query->wherePivotNull('project_group_id') : $query->wherePivot('project_group_id', $groupId);

        $page = $this->applyTagFilter($query, $filter)
            ->orderByDesc('projects.updated_at')
            ->simplePaginate(self::PER_PAGE, pageName: $groupId === null ? 'ungrouped' : 'group-'.$groupId);

        if ($open !== null && $this->groupIdOf($open) === $groupId && ! $page->getCollection()->contains('id', $open->id)) {
            $page->getCollection()->prepend($open);
            $this->pinnedIn = $groupId ?? 'ungrouped';
        }

        return $page;
    }

    private function lastPage(int $count): int
    {
        return max(1, (int) ceil($count / self::PER_PAGE));
    }

    /** The user's drawer the project sits in (pivot value, a string on some drivers), null when ungrouped. */
    private function groupIdOf(Project $project): ?int
    {
        $groupId = $project->pivot->project_group_id;

        return $groupId === null ? null : (int) $groupId;
    }

    /**
     * The open project's id, if the filter hides it; null otherwise.
     */
    private function openProjectOutside(?Project $open, TagFilter $filter): ?int
    {
        if ($open === null || $filter->isEmpty()) {
            return null;
        }

        return $this->applyTagFilter($this->user()->projects()->whereKey($open->id), $filter)->exists() ? null : $open->id;
    }
}
