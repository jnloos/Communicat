<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Experts\ExpertEditor;
use App\Livewire\Projects\EditProject;
use App\Livewire\Projects\ProjectChat;
use App\Livewire\Projects\ProjectDialogs;
use App\Livewire\Settings\Users;
use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Every confirmation names its object and ends in a button that says what happens.
 * What a dialog asks is wired per caller, so each caller is pinned here.
 */
class ConfirmTextsTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_a_project_names_it_and_the_button_says_delete_project(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $project = Project::factory()->create(['user_id' => $owner->id, 'title' => 'Schulhandys']);
        $expected = [
            'title' => __('groups.confirm.delete_project.title'),
            'message' => __('groups.confirm.delete_project.message', ['name' => 'Schulhandys']),
            'label' => 'Delete project',
        ];

        // The sidebar and the edit flyout share one text, not two copies.
        Livewire::test(ProjectDialogs::class)
            ->call('confirmDeleteProject', $project->id)
            ->assertDispatched('open-confirm', ...$expected);

        Livewire::test(EditProject::class, ['project' => $project])
            ->call('confirmDelete')
            ->assertSet('pendingMethod', 'delete')
            ->assertDispatched('open-confirm', ...$expected);

        $this->assertStringContainsString('Schulhandys', $expected['message']);
        $this->assertDatabaseHas('projects', ['id' => $project->id]);
    }

    public function test_only_the_owner_is_asked_about_deleting_from_the_edit_flyout(): void
    {
        $owner = User::factory()->create();
        $reader = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id]);
        $project->addContributingUser($reader);
        $this->actingAs($reader);

        Livewire::test(EditProject::class, ['project' => $project])->call('confirmDelete')->assertForbidden();
    }

    public function test_deleting_an_expert_names_it_and_counts_its_projects(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $expert = Expert::factory()->create(['name' => 'Ada']);

        Livewire::test(ExpertEditor::class)
            ->call('edit', $expert->id)
            ->call('confirmDelete')
            ->assertSet('pendingMethod', 'delete')
            ->assertDispatched('open-confirm', title: 'Delete expert?', label: 'Delete expert',
                message: '“Ada” sits in no project and is deleted. This cannot be undone.');

        foreach ([1, 2] as $count) {
            Project::factory()->create()->addContributingExpert($expert);

            $message = Livewire::test(ExpertEditor::class)
                ->call('edit', $expert->id)
                ->call('confirmDelete')
                ->get('confirmMessage');

            $this->assertStringContainsString("“Ada” sits in {$count} project", $message);
            $this->assertStringContainsString('turns there leave the turn budget and the metrics', $message);
        }

        $this->assertNotNull($expert->fresh());
    }

    public function test_a_non_admin_cannot_even_ask_to_delete_an_expert(): void
    {
        $this->actingAs(User::factory()->create());
        $expert = Expert::factory()->create();

        Livewire::test(ExpertEditor::class)->call('edit', $expert->id)->call('confirmDelete')->assertForbidden();
    }

    public function test_deleting_a_user_names_the_user(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $user = User::factory()->create(['name' => 'Bea', 'email' => 'bea@example.test']);

        Livewire::test(Users::class)
            ->call('confirmDelete', $user->id)
            ->assertSet('pendingMethod', 'delete')
            ->assertDispatched('open-confirm', title: 'Delete user?', label: 'Delete user',
                message: 'The user “Bea” (bea@example.test) is deleted. This cannot be undone.');

        $this->assertNotNull($user->fresh());
    }

    public function test_leaving_a_project_names_it(): void
    {
        $owner = User::factory()->create();
        $guest = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id, 'title' => 'Schulhandys']);
        $project->addContributingUser($guest);
        $this->actingAs($guest);

        Livewire::test(ProjectChat::class, ['project' => $project])
            ->call('confirmLeave')
            ->assertSet('pendingMethod', 'leaveProject')
            ->assertDispatched('open-confirm', title: 'Leave project?', label: 'Leave project',
                message: 'You leave “Schulhandys” and no longer see it. The owner can invite you again.');
    }

    public function test_the_owner_cannot_be_asked_to_leave(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id]);
        $this->actingAs($owner);

        Livewire::test(ProjectChat::class, ['project' => $project])->call('confirmLeave')->assertForbidden();
    }

    public function test_the_german_texts_name_the_object_too(): void
    {
        app()->setLocale('de');

        $this->assertStringContainsString('Schulhandys', __('groups.confirm.delete_project.message', ['name' => 'Schulhandys']));
        $this->assertStringContainsString('Ada', trans_choice('experts.confirm.message', 2, ['name' => 'Ada', 'count' => 2]));
        $this->assertStringContainsString('Bea', __('settings.users.confirm.message', ['name' => 'Bea', 'email' => 'b@x.test']));
        $this->assertStringContainsString('Schulhandys', __('chat.confirm_leave.message', ['name' => 'Schulhandys']));

        foreach (['groups.confirm.delete_project.action', 'experts.confirm.action', 'settings.users.confirm.action', 'chat.confirm_leave.action'] as $key) {
            $this->assertNotSame($key, __($key), "{$key} has no German text");
        }
    }
}
