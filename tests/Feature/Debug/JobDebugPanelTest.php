<?php

namespace Tests\Feature\Debug;

use App\Livewire\Debug\JobDebugPanel;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Exceptions\EventHandlerDoesNotExist;
use Livewire\Livewire;
use Tests\TestCase;

class JobDebugPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => true]);

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_switching_to_a_foreign_project_is_forbidden(): void
    {
        Project::factory()->create(['user_id' => $this->user->id]);
        $foreign = Project::factory()->create();

        Livewire::test(JobDebugPanel::class)
            ->set('projectId', $foreign->id)
            ->assertForbidden();
    }

    public function test_a_foreign_project_in_the_url_falls_back_to_an_own_one(): void
    {
        $own = Project::factory()->create(['user_id' => $this->user->id]);
        $foreign = Project::factory()->create();

        Livewire::withQueryParams(['project' => $foreign->id])
            ->test(JobDebugPanel::class)
            ->assertSet('projectId', $own->id);
    }

    public function test_the_picker_lists_owned_and_shared_projects_only(): void
    {
        $owned = Project::factory()->create(['user_id' => $this->user->id]);
        $shared = Project::factory()->create();
        $shared->users()->attach($this->user);
        $foreign = Project::factory()->create();

        $projects = Livewire::test(JobDebugPanel::class)->viewData('projects');

        $this->assertEqualsCanonicalizing([$owned->id, $shared->id], $projects->pluck('id')->all());
        $this->assertFalse($projects->contains('id', $foreign->id));
    }

    public function test_it_listens_on_the_channel_of_the_shown_project(): void
    {
        $own = Project::factory()->create(['user_id' => $this->user->id]);

        $component = Livewire::test(JobDebugPanel::class)->assertSet('projectId', $own->id);

        $component->dispatch("echo-private:projects.{$own->id},.JobLogUpdated")->assertOk();
    }

    /**
     * Without any project there is no channel to join; the panel must not
     * subscribe to a placeholder like `projects.` instead.
     */
    public function test_without_a_project_it_listens_to_no_job_channel(): void
    {
        $component = Livewire::test(JobDebugPanel::class)->assertSet('projectId', null);

        $this->expectException(EventHandlerDoesNotExist::class);
        $component->dispatch('echo-private:projects.,.JobLogUpdated');
    }

    /**
     * Echo subscriptions are fixed when a component mounts, so switching the
     * project reloads the page: otherwise the panel would keep following the
     * first project's channel while showing another.
     */
    public function test_switching_the_project_reloads_the_page_for_its_channel(): void
    {
        $first = Project::factory()->create(['user_id' => $this->user->id]);
        $second = Project::factory()->create(['user_id' => $this->user->id]);

        Livewire::withQueryParams(['project' => $first->id])
            ->test(JobDebugPanel::class)
            ->set('projectId', $second->id)
            ->assertRedirect(route('debug.jobs', ['project' => $second->id]));
    }
}
