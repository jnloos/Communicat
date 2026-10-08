<?php

namespace Tests\Feature\Projects;

use App\Livewire\Projects\ProjectNavigation;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectNavigationTest extends TestCase
{
    use RefreshDatabase;

    private function projectOwnedBy(User $user, string $title = 'Projekt'): Project
    {
        $project = Project::factory()->create(['user_id' => $user->id, 'title' => $title]);
        $project->addContributingUser($user);

        return $project;
    }

    private function groupFor(User $user, string $name): ProjectGroup
    {
        return $user->projectGroups()->create(['name' => $name, 'position' => 0]);
    }

    /** The view is handed groups and their projects; this is what the sidebar renders. */
    private function projectsInGroup(mixed $component, ProjectGroup $group): Collection
    {
        return collect($component->viewData('grouped')[$group->id] ?? []);
    }

    public function test_a_project_moves_into_a_group_and_shows_up_under_it(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $project = $this->projectOwnedBy($user);
        $group = $this->groupFor($user, 'Pilotläufe');

        $component = Livewire::test(ProjectNavigation::class)
            ->call('toggleGroup', $project->id, $group->id);

        $this->assertSame([$project->id], $this->projectsInGroup($component, $group)->pluck('id')->all());
        $this->assertSame([], $component->viewData('ungrouped')->pluck('id')->all());
    }

    public function test_ticking_the_same_group_again_takes_the_project_out(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $project = $this->projectOwnedBy($user);
        $group = $this->groupFor($user, 'Pilotläufe');

        $component = Livewire::test(ProjectNavigation::class)
            ->call('toggleGroup', $project->id, $group->id)
            ->call('toggleGroup', $project->id, $group->id);

        $this->assertSame([], $this->projectsInGroup($component, $group)->pluck('id')->all());
        $this->assertSame([$project->id], $component->viewData('ungrouped')->pluck('id')->all());
    }

    /**
     * The core requirement: the grouping hangs on the contributor's own pivot
     * row, so a shared project can sit in a different drawer for every person
     * who reads it.
     */
    public function test_a_shared_project_sits_in_each_users_own_group(): void
    {
        $owner = User::factory()->create();
        $reader = User::factory()->create();

        $project = $this->projectOwnedBy($owner);
        $project->addContributingUser($reader);

        $ownerGroup = $this->groupFor($owner, 'Meine Studie');
        $readerGroup = $this->groupFor($reader, 'Geteiltes');

        $this->actingAs($owner);
        $ownerView = Livewire::test(ProjectNavigation::class)
            ->call('toggleGroup', $project->id, $ownerGroup->id);

        $this->actingAs($reader);
        $readerView = Livewire::test(ProjectNavigation::class)
            ->call('toggleGroup', $project->id, $readerGroup->id);

        $this->assertSame([$project->id], $this->projectsInGroup($ownerView, $ownerGroup)->pluck('id')->all());
        $this->assertSame([$project->id], $this->projectsInGroup($readerView, $readerGroup)->pluck('id')->all());

        // And neither filing overwrote the other.
        $this->actingAs($owner);
        $ownerAgain = Livewire::test(ProjectNavigation::class);
        $this->assertSame([$project->id], $this->projectsInGroup($ownerAgain, $ownerGroup)->pluck('id')->all());
    }

    public function test_a_project_cannot_be_filed_into_a_foreign_group(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        $this->actingAs($user);

        $project = $this->projectOwnedBy($user);
        $foreign = $this->groupFor($stranger, 'Fremde Gruppe');

        Livewire::test(ProjectNavigation::class)
            ->call('toggleGroup', $project->id, $foreign->id)
            ->assertStatus(404);

        $this->assertDatabaseHas('project_contributors', [
            'project_id' => $project->id,
            'contributor_id' => $user->id,
            'project_group_id' => null,
        ]);
    }

    public function test_a_contributor_who_is_not_the_owner_can_neither_rename_nor_delete(): void
    {
        $owner = User::factory()->create();
        $reader = User::factory()->create();

        $project = $this->projectOwnedBy($owner, 'Originaltitel');
        $project->addContributingUser($reader);

        $this->actingAs($reader);

        Livewire::test(ProjectNavigation::class)
            ->call('startRenamingProject', $project->id)
            ->assertForbidden();

        Livewire::test(ProjectNavigation::class)
            ->call('deleteProject', $project->id)
            ->assertForbidden();

        // Even with the id already sitting in the component's state — put there
        // while the owner was signed in — the save authorizes again.
        $this->actingAs($owner);
        $component = Livewire::test(ProjectNavigation::class)->call('startRenamingProject', $project->id);

        $this->actingAs($reader);
        $component->set('projectTitle', 'Gekapert')->call('saveProjectTitle')->assertForbidden();

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'title' => 'Originaltitel']);
    }

    public function test_deleting_a_group_leaves_its_projects_standing(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $project = $this->projectOwnedBy($user);
        $group = $this->groupFor($user, 'Pilotläufe');

        Livewire::test(ProjectNavigation::class)->call('toggleGroup', $project->id, $group->id);

        $component = Livewire::test(ProjectNavigation::class)->call('deleteGroup', $group->id);

        $this->assertDatabaseMissing('project_groups', ['id' => $group->id]);
        $this->assertDatabaseHas('projects', ['id' => $project->id]);
        $this->assertSame([$project->id], $component->viewData('ungrouped')->pluck('id')->all());
    }

    public function test_an_empty_group_stays_visible(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $group = $this->groupFor($user, 'Noch leer');

        $component = Livewire::test(ProjectNavigation::class)
            ->assertSee('Noch leer')
            ->assertSee(__('groups.empty'));

        $this->assertSame([$group->id], $component->viewData('groups')->pluck('id')->all());
        $this->assertSame([], $this->projectsInGroup($component, $group)->all());
    }

    /**
     * The menu's "Group" heading labels the list of groups to file into. With
     * no group there is no list, and the heading would sit alone above the
     * entry that creates the first one.
     */
    public function test_the_menus_group_heading_appears_only_once_a_group_exists(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->projectOwnedBy($user);

        Livewire::test(ProjectNavigation::class)
            ->assertDontSeeHtml('data-flux-menu-heading');

        $this->groupFor($user, 'Pilot');

        Livewire::test(ProjectNavigation::class)
            ->assertSeeHtml('data-flux-menu-heading');
    }

    public function test_a_new_group_is_created_and_the_project_moved_in_one_go(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $project = $this->projectOwnedBy($user);

        $component = Livewire::test(ProjectNavigation::class)
            ->call('startNewGroup', $project->id)
            ->set('groupName', 'Statuseffekt')
            ->call('createGroup');

        $group = $user->projectGroups()->firstOrFail();
        $this->assertSame('Statuseffekt', $group->name);
        $this->assertSame([$project->id], $this->projectsInGroup($component, $group)->pluck('id')->all());
    }

    public function test_two_groups_of_one_user_cannot_share_a_name(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $project = $this->projectOwnedBy($user);
        $this->groupFor($user, 'Statuseffekt');

        Livewire::test(ProjectNavigation::class)
            ->call('startNewGroup', $project->id)
            ->set('groupName', 'Statuseffekt')
            ->call('createGroup')
            ->assertHasErrors('groupName');

        $this->assertSame(1, $user->projectGroups()->count());
    }

    /** The same name under two people is no clash — groups are private. */
    public function test_two_users_may_each_have_a_group_of_the_same_name(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        $this->groupFor($stranger, 'Statuseffekt');

        $this->actingAs($user);
        $project = $this->projectOwnedBy($user);

        Livewire::test(ProjectNavigation::class)
            ->call('startNewGroup', $project->id)
            ->set('groupName', 'Statuseffekt')
            ->call('createGroup')
            ->assertHasNoErrors();

        $this->assertSame(1, $user->projectGroups()->count());
    }

    public function test_a_group_is_renamed_and_keeps_its_projects(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $project = $this->projectOwnedBy($user);
        $group = $this->groupFor($user, 'Alt');
        $project->fileInGroupFor($user, $group->id);

        $component = Livewire::test(ProjectNavigation::class)
            ->call('startRenamingGroup', $group->id)
            ->assertSet('groupName', 'Alt')
            ->set('groupName', 'Neu')
            ->call('saveGroupName')
            ->assertHasNoErrors();

        $this->assertSame('Neu', $group->fresh()->name);
        $this->assertSame([$project->id], $this->projectsInGroup($component, $group)->pluck('id')->all());
    }

    /** The unique rule must ignore the group being renamed, or saving its own name would fail. */
    public function test_renaming_a_group_to_its_own_name_is_no_clash(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $group = $this->groupFor($user, 'Pilotläufe');

        Livewire::test(ProjectNavigation::class)
            ->call('startRenamingGroup', $group->id)
            ->call('saveGroupName')
            ->assertHasNoErrors();

        $this->assertSame('Pilotläufe', $group->fresh()->name);
    }

    public function test_a_group_cannot_be_renamed_onto_another_of_the_same_users_groups(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $group = $this->groupFor($user, 'Pilotläufe');
        $this->groupFor($user, 'Hauptlauf');

        Livewire::test(ProjectNavigation::class)
            ->call('startRenamingGroup', $group->id)
            ->set('groupName', 'Hauptlauf')
            ->call('saveGroupName')
            ->assertHasErrors('groupName');

        $this->assertSame('Pilotläufe', $group->fresh()->name);
    }

    public function test_a_foreign_group_cannot_be_renamed_or_deleted(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        $this->actingAs($user);

        $foreign = $this->groupFor($stranger, 'Fremde Gruppe');

        Livewire::test(ProjectNavigation::class)
            ->call('startRenamingGroup', $foreign->id)
            ->assertStatus(404);

        Livewire::test(ProjectNavigation::class)
            ->call('deleteGroup', $foreign->id)
            ->assertStatus(404);

        $this->assertDatabaseHas('project_groups', ['id' => $foreign->id, 'name' => 'Fremde Gruppe']);
    }

    public function test_the_owner_renames_and_deletes_the_project(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $project = $this->projectOwnedBy($user, 'Alt');

        Livewire::test(ProjectNavigation::class)
            ->call('startRenamingProject', $project->id)
            ->set('projectTitle', 'Neu')
            ->call('saveProjectTitle');

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'title' => 'Neu']);

        Livewire::test(ProjectNavigation::class, ['currentProjectId' => $project->id])
            ->call('deleteProject', $project->id)
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
    }

    /**
     * Both deletions go through the shared confirm dialog (NeedsConfirmation ->
     * open-confirm -> x-confirm-modal), the same one the rest of the app uses.
     * Deleting a run takes its measurements with it, so the menu entry must ask
     * rather than act -- and asking has to be wired up, not merely intended.
     */
    public function test_deleting_a_project_asks_through_the_shared_confirm_dialog(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $project = $this->projectOwnedBy($user);

        $component = Livewire::test(ProjectNavigation::class, ['currentProjectId' => $project->id])
            ->call('confirmDeleteProject', $project->id)
            ->assertDispatched('open-confirm');

        // Nothing is gone until the dialog answers back.
        $this->assertDatabaseHas('projects', ['id' => $project->id]);

        $component->call('executeConfirmed');

        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
    }

    public function test_deleting_a_group_asks_through_the_shared_confirm_dialog(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $group = $this->groupFor($user, 'Pilot');

        $component = Livewire::test(ProjectNavigation::class)
            ->call('confirmDeleteGroup', $group->id)
            ->assertDispatched('open-confirm');

        $this->assertDatabaseHas('project_groups', ['id' => $group->id]);

        $component->call('executeConfirmed');

        $this->assertDatabaseMissing('project_groups', ['id' => $group->id]);
    }

    /** Deleting a project one is not looking at must not throw the user out of the one they are. */
    public function test_deleting_another_project_does_not_redirect(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $open = $this->projectOwnedBy($user, 'Offen');
        $other = $this->projectOwnedBy($user, 'Anderes');

        Livewire::test(ProjectNavigation::class, ['currentProjectId' => $open->id])
            ->call('deleteProject', $other->id)
            ->assertNoRedirect();

        $this->assertDatabaseMissing('projects', ['id' => $other->id]);
        $this->assertDatabaseHas('projects', ['id' => $open->id]);
    }

    public function test_a_project_the_user_has_no_part_in_is_invisible_to_the_navigation(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        $this->actingAs($user);

        $foreign = $this->projectOwnedBy($stranger, 'Fremd');
        $group = $this->groupFor($user, 'Meine');

        Livewire::test(ProjectNavigation::class)
            ->call('toggleGroup', $foreign->id, $group->id)
            ->assertForbidden();
    }

    public function test_the_sidebar_renders_the_navigation(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $project = $this->projectOwnedBy($user, 'Sichtbares Projekt');
        $group = $this->groupFor($user, 'Pilotläufe');

        Livewire::test(ProjectNavigation::class)
            ->call('toggleGroup', $project->id, $group->id)
            ->assertSee('Pilotläufe')
            ->assertSee('Sichtbares Projekt');
    }

    /**
     * A group starts folded, so a sidebar full of pilot cells stays a list of
     * drawers. The one exception is the group holding the open project: folding
     * that would hide the very row that says where the user is.
     */
    public function test_groups_start_folded_except_the_one_holding_the_open_project(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $open = $this->projectOwnedBy($user, 'Offen');
        $other = $this->projectOwnedBy($user, 'Anderes');
        $current = $this->groupFor($user, 'Aktuell');
        $folded = $this->groupFor($user, 'Zugeklappt');
        $open->fileInGroupFor($user, $current->id);
        $other->fileInGroupFor($user, $folded->id);

        $html = Livewire::test(ProjectNavigation::class, ['currentProjectId' => $open->id])->html();

        $this->assertSame([true, false], [
            $this->groupIsExpanded($html, $current),
            $this->groupIsExpanded($html, $folded),
        ]);
    }

    /**
     * Flux marks an expanded group with a bare `open` on its ui-disclosure. The
     * tag is cut at Flux's own data attribute, not at the first ">": the class
     * list carries "[&>button]".
     */
    private function groupIsExpanded(string $html, ProjectGroup $group): bool
    {
        preg_match('/wire:key="nav-group-'.$group->id.'(?:-[a-z]+)?".*?(<ui-disclosure.*?data-flux-sidebar-group>)/s', $html, $match);

        return preg_match('/\sopen\s/', $match[1]) === 1;
    }

    /** The component replaced a block in the layout, so the real page has to render it. */
    public function test_the_project_page_renders_the_grouped_sidebar(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $project = $this->projectOwnedBy($user, 'Sichtbares Projekt');
        $group = $this->groupFor($user, 'Pilotläufe');
        $project->fileInGroupFor($user, $group->id);

        $this->get(route('project.show', $project))
            ->assertOk()
            ->assertSee('Pilotläufe')
            ->assertSee('Sichtbares Projekt');
    }

    public function test_the_tag_filter_hides_projects_and_drawers_without_a_match(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'condition']);
        $status = $condition->values()->create(['name' => 'status']);

        $tagged = $this->projectOwnedBy($user, 'Mit Status');
        $tagged->setTag($condition, $status);
        $untagged = $this->projectOwnedBy($user, 'Ohne');
        $full = $this->groupFor($user, 'Voll');
        $empty = $this->groupFor($user, 'Leer');
        $tagged->fileInGroupFor($user, $full->id);

        $unfiltered = Livewire::test(ProjectNavigation::class);
        $this->assertSame([$full->id, $empty->id], $unfiltered->viewData('groups')->pluck('id')->sort()->values()->all());
        $this->assertSame([$untagged->id], $unfiltered->viewData('ungrouped')->pluck('id')->all());

        $filtered = Livewire::test(ProjectNavigation::class)->set('tagFilter', [(string) $status->id]);
        $this->assertSame([$full->id], $filtered->viewData('groups')->pluck('id')->all());
        $this->assertSame([$tagged->id], $this->projectsInGroup($filtered, $full)->pluck('id')->all());
        $this->assertSame([], $filtered->viewData('ungrouped')->pluck('id')->all());
        $this->assertSame(1, $filtered->viewData('activeFilterCount'));
    }

    public function test_merge_takes_any_value_and_cut_every_picked_category(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'condition']);
        $status = $condition->values()->create(['name' => 'status']);
        $control = $condition->values()->create(['name' => 'no-status']);
        $topic = TagCategory::create(['scope' => TagScope::Project, 'name' => 'thema']);
        $school = $topic->values()->create(['name' => 'Schule']);

        $a = $this->projectOwnedBy($user, 'A');
        $a->setTag($condition, $status);
        $a->setTag($topic, $school);
        $b = $this->projectOwnedBy($user, 'B');
        $b->setTag($condition, $control);

        $across = [(string) $status->id, (string) $school->id];
        $sameCategory = [(string) $status->id, (string) $control->id];

        $merge = Livewire::test(ProjectNavigation::class)->set('tagFilterMode', 'merge');
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $merge->set('tagFilter', $sameCategory)->viewData('ungrouped')->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $merge->set('tagFilter', [(string) $control->id, (string) $school->id])->viewData('ungrouped')->pluck('id')->all());

        // Cut is faceted: OR within a category, AND across categories.
        $cut = Livewire::test(ProjectNavigation::class)->set('tagFilterMode', 'cut');
        $this->assertSame([$a->id], $cut->set('tagFilter', $across)->viewData('ungrouped')->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $cut->set('tagFilter', $sameCategory)->viewData('ungrouped')->pluck('id')->all());
        $this->assertSame([], $cut->set('tagFilter', [(string) $control->id, (string) $school->id])->viewData('ungrouped')->pluck('id')->all());
    }

    public function test_the_filter_mode_defaults_to_cut_and_falls_back_to_it_when_forged(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ProjectNavigation::class)
            ->assertSet('tagFilterMode', 'cut')
            ->set('tagFilterMode', 'xor')
            ->assertSet('tagFilterMode', 'cut')
            ->assertViewHas('tagFilterMode', 'cut');
    }

    public function test_the_filter_mode_survives_the_next_page(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ProjectNavigation::class)->set('tagFilterMode', 'merge');

        Livewire::test(ProjectNavigation::class)->assertSet('tagFilterMode', 'merge');
    }

    public function test_the_popover_offers_the_values_as_flux_pills_bound_to_the_filter(): void
    {
        $this->actingAs(User::factory()->create());
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'condition']);
        $status = $condition->values()->create(['name' => 'status']);

        Livewire::test(ProjectNavigation::class)
            ->assertSeeHtml('wire:model.live="tagFilter"')
            ->assertSeeHtml('data-flux-checkbox-pills')
            ->assertSeeHtml('value="'.$status->id.'"');
    }

    public function test_clearing_empties_the_values_but_keeps_the_mode(): void
    {
        $this->actingAs(User::factory()->create());
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'condition']);
        $status = $condition->values()->create(['name' => 'status']);

        Livewire::test(ProjectNavigation::class)
            ->set('tagFilterMode', 'merge')
            ->set('tagFilter', [(string) $status->id])
            ->call('clearTagFilter')
            ->assertSet('tagFilter', [])
            ->assertSet('tagFilterMode', 'merge')
            ->assertDontSee(__('tags.filter.clear_label'));
    }

    public function test_the_popover_counts_matching_projects_out_of_all_of_the_users(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'condition']);
        $status = $condition->values()->create(['name' => 'status']);
        $this->projectOwnedBy($user, 'Mit Status')->setTag($condition, $status);
        $open = $this->projectOwnedBy($user, 'Offen');
        $this->projectOwnedBy($user, 'Anderes');
        Project::factory()->create();

        $unfiltered = Livewire::test(ProjectNavigation::class, ['currentProjectId' => $open->id]);
        $this->assertSame(3, $unfiltered->viewData('matchCount'));
        $this->assertSame(3, $unfiltered->viewData('projectCount'));

        // The open project stays in the list, dimmed, but it is no match.
        $filtered = $unfiltered->set('tagFilter', [(string) $status->id]);
        $this->assertSame(1, $filtered->viewData('matchCount'));
        $this->assertSame(3, $filtered->viewData('projectCount'));
        $filtered->assertSee('1 of 3 projects');
    }

    public function test_the_count_names_one_project_in_the_singular(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        TagCategory::create(['scope' => TagScope::Project, 'name' => 'condition'])->values()->create(['name' => 'status']);
        $this->projectOwnedBy($user);

        Livewire::test(ProjectNavigation::class)
            ->assertSee('1 of 1 project')
            ->assertDontSee('1 of 1 projects');
    }

    public function test_merge_counts_every_project_with_any_value_but_not_the_dimmed_open_one(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'condition']);
        $status = $condition->values()->create(['name' => 'status']);
        $control = $condition->values()->create(['name' => 'no-status']);
        $a = $this->projectOwnedBy($user, 'A');
        $a->setTag($condition, $status);
        $b = $this->projectOwnedBy($user, 'B');
        $b->setTag($condition, $control);
        $open = $this->projectOwnedBy($user, 'Offen');
        $this->projectOwnedBy($user, 'Anderes');
        $this->projectOwnedBy($user, 'Drittes');

        $component = Livewire::test(ProjectNavigation::class, ['currentProjectId' => $open->id])
            ->set('tagFilterMode', 'merge')
            ->set('tagFilter', [(string) $status->id, (string) $control->id]);

        $this->assertSame(2, $component->viewData('matchCount'));
        $this->assertSame(5, $component->viewData('projectCount'));
        $this->assertSame($open->id, $component->viewData('outsideFilterProjectId'));
        $this->assertEqualsCanonicalizing([$a->id, $b->id, $open->id], $component->viewData('ungrouped')->pluck('id')->all());
        $component->assertSee('2 of 5 projects');
    }

    public function test_an_active_filter_unfolds_every_remaining_group(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'condition']);
        $status = $condition->values()->create(['name' => 'status']);
        $first = $this->groupFor($user, 'Eins');
        $second = $this->groupFor($user, 'Zwei');
        foreach ([$first, $second] as $group) {
            $project = $this->projectOwnedBy($user, $group->name);
            $project->setTag($condition, $status);
            $project->fileInGroupFor($user, $group->id);
        }

        $component = Livewire::test(ProjectNavigation::class);
        $this->assertSame([false, false], [$this->groupIsExpanded($component->html(), $first), $this->groupIsExpanded($component->html(), $second)]);

        $html = $component->set('tagFilter', [(string) $status->id])->html();
        $this->assertSame([true, true], [$this->groupIsExpanded($html, $first), $this->groupIsExpanded($html, $second)]);
    }

    /**
     * Flux keeps a disclosure's open state across a morph, so the server's
     * `expanded` alone would not unfold a group already on the page: the key
     * has to change with the filter state.
     */
    public function test_the_group_key_changes_when_a_filter_becomes_active(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'condition']);
        $status = $condition->values()->create(['name' => 'status']);
        $group = $this->groupFor($user, 'Eins');
        $project = $this->projectOwnedBy($user);
        $project->setTag($condition, $status);
        $project->fileInGroupFor($user, $group->id);

        $component = Livewire::test(ProjectNavigation::class);
        $before = $this->groupKey($component->html(), $group);
        $after = $this->groupKey($component->set('tagFilter', [(string) $status->id])->html(), $group);

        $this->assertNotNull($before);
        $this->assertNotNull($after);
        $this->assertNotSame($before, $after);
    }

    private function groupKey(string $html, ProjectGroup $group): ?string
    {
        return preg_match('/wire:key="(nav-group-'.$group->id.'(?:-[a-z]+)?)"/', $html, $match) === 1 ? $match[1] : null;
    }

    public function test_an_active_filter_shows_one_summary_line_with_count_and_tags(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'condition']);
        $status = $condition->values()->create(['name' => 'status']);
        $control = $condition->values()->create(['name' => 'no-status']);
        $this->projectOwnedBy($user, 'A')->setTag($condition, $status);
        $this->projectOwnedBy($user, 'B')->setTag($condition, $control);
        $this->projectOwnedBy($user, 'C');

        $component = Livewire::test(ProjectNavigation::class);
        $component->assertDontSee(__('tags.filter.clear_label'));

        $component->set('tagFilter', [(string) $status->id, (string) $control->id])
            ->assertSee('2 of 3 · Condition: status, Condition: no-status')
            ->assertSee(__('tags.filter.clear_label'));
    }

    public function test_cut_with_two_values_of_one_category_finds_projects_with_either(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'condition']);
        $status = $condition->values()->create(['name' => 'status']);
        $control = $condition->values()->create(['name' => 'no-status']);
        $tagged = $this->projectOwnedBy($user);
        $tagged->setTag($condition, $status);

        $component = Livewire::test(ProjectNavigation::class)
            ->set('tagFilter', [(string) $status->id, (string) $control->id])
            ->assertSet('tagFilterMode', 'cut')
            ->assertDontSee(__('tags.filter.no_matches'));

        $this->assertSame([$tagged->id], $component->viewData('ungrouped')->pluck('id')->all());
    }

    public function test_no_match_is_reported_even_while_the_open_project_stays_dimmed(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'condition']);
        $status = $condition->values()->create(['name' => 'status']);
        $open = $this->projectOwnedBy($user, 'Offen');

        $component = Livewire::test(ProjectNavigation::class, ['currentProjectId' => $open->id])
            ->set('tagFilter', [(string) $status->id]);

        $this->assertSame([$open->id], $component->viewData('ungrouped')->pluck('id')->all());
        $component->assertSee(__('tags.filter.no_matches'));
    }

    public function test_the_funnel_names_how_many_values_are_active(): void
    {
        $this->actingAs(User::factory()->create());
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'condition']);
        $status = $condition->values()->create(['name' => 'status']);
        $control = $condition->values()->create(['name' => 'no-status']);

        Livewire::test(ProjectNavigation::class)
            ->assertDontSee('Filter projects (')
            ->set('tagFilter', [(string) $status->id, (string) $control->id])
            ->assertSee('Filter projects (2 active)');
    }

    public function test_the_projects_label_shows_without_categories_and_skips_the_counts(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ProjectNavigation::class)
            ->assertSee(__('tags.filter.label'))
            ->assertDontSee(__('tags.filter.title'))
            ->assertViewHas('matchCount', null)
            ->assertViewHas('projectCount', null);
    }

    public function test_the_tag_filter_survives_the_next_page_and_resets_in_one_click(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'condition']);
        $status = $condition->values()->create(['name' => 'status']);

        Livewire::test(ProjectNavigation::class)->set('tagFilter', [(string) $status->id]);

        Livewire::test(ProjectNavigation::class)
            ->assertSet('tagFilter', [(string) $status->id])
            ->call('clearTagFilter')
            ->assertSet('tagFilter', [])
            ->assertViewHas('activeFilterCount', 0);
    }

    public function test_a_deleted_value_in_the_filter_is_ignored(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $project = $this->projectOwnedBy($user);
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'condition']);
        $gone = $condition->values()->create(['name' => 'weg']);
        $goneId = $gone->id;
        $gone->delete();

        $component = Livewire::test(ProjectNavigation::class)->set('tagFilter', [(string) $goneId]);

        $this->assertSame([$project->id], $component->viewData('ungrouped')->pluck('id')->all());
        $this->assertSame(0, $component->viewData('activeFilterCount'));
    }

    public function test_a_forged_nested_tag_filter_is_ignored_without_an_error(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $project = $this->projectOwnedBy($user);

        $component = Livewire::test(ProjectNavigation::class)->set('tagFilter', [['1', '2'], 'x']);

        $this->assertSame([$project->id], $component->viewData('ungrouped')->pluck('id')->all());
        $this->assertSame(0, $component->viewData('activeFilterCount'));
    }

    public function test_the_open_project_stays_visible_dimmed_when_it_does_not_match_the_filter(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'condition']);
        $status = $condition->values()->create(['name' => 'status']);
        $tagged = $this->projectOwnedBy($user, 'Mit Status');
        $tagged->setTag($condition, $status);
        $open = $this->projectOwnedBy($user, 'Offen');
        $other = $this->projectOwnedBy($user, 'Anderes');
        $foreign = Project::factory()->create();

        $component = Livewire::test(ProjectNavigation::class, ['currentProjectId' => $open->id])
            ->set('tagFilter', [(string) $status->id]);

        $this->assertEqualsCanonicalizing([$tagged->id, $open->id], $component->viewData('ungrouped')->pluck('id')->all());
        $this->assertSame($open->id, $component->viewData('outsideFilterProjectId'));
        // Dimmed, but the tooltip still carries the full title.
        $component->assertSeeHtml('title="'.e(__('tags.filter.outside', ['title' => 'Offen'])).'"');

        $stranger = Livewire::test(ProjectNavigation::class, ['currentProjectId' => $foreign->id])
            ->set('tagFilter', [(string) $status->id]);
        $this->assertSame([$tagged->id], $stranger->viewData('ungrouped')->pluck('id')->all());
        $this->assertNull($stranger->viewData('outsideFilterProjectId'));
    }

    public function test_the_popover_names_both_modes(): void
    {
        $this->actingAs(User::factory()->create());
        TagCategory::create(['scope' => TagScope::Project, 'name' => 'condition'])->values()->create(['name' => 'status']);

        Livewire::test(ProjectNavigation::class)
            ->assertSee(__('tags.filter.modes.merge.label'))
            ->assertSee(__('tags.filter.modes.cut.label'))
            ->assertSee('Projects that match every picked category');
    }
}
