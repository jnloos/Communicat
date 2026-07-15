<?php

namespace Tests\Feature;

use App\Livewire\Experts\ExpertEditor;
use App\Livewire\Projects\CreateProject;
use App\Livewire\Projects\EditProject;
use App\Livewire\Projects\SelectContributors;
use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Guards for the restricted non-admin ("study participant") view:
 * settings pages, expert editing, memory reduction and user contributors
 * are admin-only; experts can still be added and personas viewed.
 */
class StudyModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_pages_are_admin_only(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/settings/profile')->assertForbidden();
        $this->get('/settings/password')->assertForbidden();
        $this->get('/settings/appearance')->assertForbidden();

        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $this->get('/settings/profile')->assertOk();
    }

    public function test_non_admin_cannot_edit_or_delete_experts(): void
    {
        $expert = Expert::factory()->create();
        $this->actingAs(User::factory()->create());

        Livewire::test(ExpertEditor::class)
            ->call('edit', $expert->id)
            ->assertForbidden();

        Livewire::test(ExpertEditor::class)
            ->call('save')
            ->assertForbidden();

        Livewire::test(ExpertEditor::class)
            ->call('delete')
            ->assertForbidden();
    }

    public function test_non_admin_project_creation_always_uses_standard_frequency(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateProject::class)
            ->set('title', 'Study Project')
            ->set('description', 'desc')
            ->set('frequency', 5)
            ->call('save');

        $project = Project::where('title', 'Study Project')->firstOrFail();
        $this->assertSame(10, $project->settings['summary_frequency']);
    }

    public function test_non_admin_cannot_edit_project(): void
    {
        $owner = User::factory()->create();
        $project = Project::withoutEvents(fn () => Project::create([
            'title' => 'x', 'description' => 'd',
            'settings' => ['summary_frequency' => 5],
            'user_id' => $owner->id,
        ]));

        $this->actingAs($owner);

        Livewire::test(EditProject::class, ['project' => $project])
            ->set('title', 'renamed')
            ->set('frequency', 20)
            ->call('save')
            ->assertForbidden();

        $project->refresh();
        $this->assertSame('x', $project->title);
        $this->assertSame(5, $project->settings['summary_frequency']);
    }

    public function test_admin_edit_does_not_wipe_discussion_state(): void
    {
        $owner = User::factory()->create(['is_admin' => true]);
        $project = Project::withoutEvents(fn () => Project::create([
            'title' => 'x', 'description' => 'd',
            'settings' => ['summary_frequency' => 5, 'recent_speakers' => [7]],
            'user_id' => $owner->id,
        ]));

        $this->actingAs($owner);

        Livewire::test(EditProject::class, ['project' => $project])
            ->set('title', 'renamed')
            ->call('save');

        $project->refresh();
        $this->assertSame('renamed', $project->title);
        // Editing must not wipe unrelated discussion state.
        $this->assertSame([7], $project->settings['recent_speakers']);
    }

    public function test_admin_edit_can_change_frequency(): void
    {
        $owner = User::factory()->create(['is_admin' => true]);
        $project = Project::withoutEvents(fn () => Project::create([
            'title' => 'x', 'description' => 'd',
            'settings' => ['summary_frequency' => 10],
            'user_id' => $owner->id,
        ]));

        $this->actingAs($owner);

        Livewire::test(EditProject::class, ['project' => $project])
            ->set('frequency', 20)
            ->call('save');

        $this->assertSame(20, $project->refresh()->settings['summary_frequency']);
    }

    public function test_non_admin_owner_cannot_add_users_but_can_add_experts(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $expert = Expert::factory()->create();
        $project = Project::withoutEvents(fn () => Project::create([
            'title' => 'x', 'description' => 'd', 'settings' => [],
            'user_id' => $owner->id,
        ]));

        $this->actingAs($owner);

        Livewire::test(SelectContributors::class, ['project' => $project])
            ->call('addUser', $other->id)
            ->assertForbidden();
        $this->assertFalse($project->users()->whereKey($other->id)->exists());

        Livewire::test(SelectContributors::class, ['project' => $project])
            ->call('addExpert', $expert->id);
        $this->assertTrue($project->experts()->whereKey($expert->id)->exists());
    }

    public function test_admin_owner_can_add_users(): void
    {
        $owner = User::factory()->create(['is_admin' => true]);
        $other = User::factory()->create();
        $project = Project::withoutEvents(fn () => Project::create([
            'title' => 'x', 'description' => 'd', 'settings' => [],
            'user_id' => $owner->id,
        ]));

        $this->actingAs($owner);

        Livewire::test(SelectContributors::class, ['project' => $project])
            ->call('addUser', $other->id);

        $this->assertTrue($project->users()->whereKey($other->id)->exists());
    }

    public function test_non_admin_cannot_create_from_file(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateProject::class)
            ->call('createFromFile')
            ->assertForbidden();

        $this->get(route('project.new'))
            ->assertOk()
            ->assertDontSee('Create from File');

        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $this->get(route('project.new'))
            ->assertOk()
            ->assertSee('Create from File');
    }

    public function test_project_page_hides_admin_ui_for_non_admins(): void
    {
        $owner = User::factory()->create();
        $project = Project::withoutEvents(fn () => Project::create([
            'title' => 'x', 'description' => 'd', 'settings' => [],
            'user_id' => $owner->id,
        ]));
        $project->users()->syncWithoutDetaching($owner->id);

        $this->actingAs($owner);
        $response = $this->get(route('project.show', $project))->assertOk();

        $response->assertDontSee('Repository');
        $response->assertDontSee('settings/profile');
        $response->assertDontSee('Memory Reduction');
        $response->assertDontSee('voice-stage', escape: false);
        // Non-admins see the selected-experts avatar row instead of tabs.
        $response->assertSee('Noch keine Experten ausgewählt.');
        // Project settings are read-only for non-admins.
        $response->assertDontSee('Update Project');

        $admin = User::factory()->create(['is_admin' => true]);
        $project->users()->syncWithoutDetaching($admin->id);

        $this->actingAs($admin);
        $response = $this->get(route('project.show', $project))->assertOk();

        $response->assertSee('Repository');
        $response->assertSee('settings/profile');
        $response->assertSee('Memory Reduction');
    }

    public function test_experts_page_is_read_only_for_non_admins(): void
    {
        Expert::factory()->create(['name' => 'Alice', 'voice_id' => config('voices.female.0.id')]);

        $this->actingAs(User::factory()->create());
        $response = $this->get(route('experts'))->assertOk();

        $response->assertSee('Alice');
        $response->assertDontSee('Create Expert');
        $response->assertSee('open-expert-details', escape: false);
        $response->assertDontSee('edit_expert', escape: false);

        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $response = $this->get(route('experts'))->assertOk();

        $response->assertSee('Create Expert');
        $response->assertSee('edit_expert', escape: false);
    }

    public function test_expert_details_flyout_shows_persona_read_only(): void
    {
        $expert = Expert::factory()->create([
            'profile' => 'Testprofil',
            'core_beliefs' => ['Überzeugung A'],
            'knowledge_limits' => ['Grenze B'],
            'style' => 'knapp',
        ]);
        $this->actingAs(User::factory()->create());

        Livewire::test(\App\Livewire\Experts\ExpertDetailsFlyout::class)
            ->call('openFor', $expert->id)
            ->assertSee('Testprofil')
            ->assertSee('Überzeugung A')
            ->assertSee('Grenze B')
            ->assertSee('knapp');
    }
}
