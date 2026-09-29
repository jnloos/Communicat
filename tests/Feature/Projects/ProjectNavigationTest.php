<?php

namespace Tests\Feature\Projects;

use App\Livewire\Projects\ProjectNavigation;
use App\Models\Project;
use App\Models\ProjectGroup;
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
}
