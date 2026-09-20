<?php

namespace Tests\Feature;

use App\Livewire\Projects\CreateProject;
use App\Livewire\Projects\EditProject;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectFormsTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_project_stores_pipeline_model_owner_and_a_welcome_message(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(CreateProject::class)
            ->assertSet('pipeline', 'RoundRobinPipeline')
            ->set('title', 'KI an Schulen')
            ->set('description', 'Sollen Schulen KI-Werkzeuge erlauben?')
            ->set('model', 'anthropic')
            ->call('save')
            ->assertHasNoErrors();

        $project = Project::sole();

        $this->assertSame('RoundRobinPipeline', $project->pipeline);
        $this->assertSame('anthropic', $project->model);
        $this->assertSame($user->id, $project->user_id);
        $this->assertTrue($project->users()->whereKey($user->id)->exists());
        $this->assertSame(1, $project->messages()->count());
    }

    public function test_unknown_pipelines_and_models_are_rejected(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateProject::class)
            ->set('title', 'x')
            ->set('description', 'y')
            ->set('pipeline', 'NopePipeline')
            ->set('model', 'nope')
            ->call('save')
            ->assertHasErrors(['pipeline', 'model']);
    }

    public function test_creating_a_project_outside_a_request_needs_no_logged_in_user(): void
    {
        $owner = User::factory()->create();

        $project = Project::create(['title' => 'Lauf 1', 'description' => 'Thema', 'user_id' => $owner->id]);

        $this->assertSame($owner->id, $project->user_id);
        $this->assertSame(0, $project->messages()->count());
    }

    public function test_editing_keeps_the_long_term_memory(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $project = Project::factory()->create(['user_id' => $owner->id, 'long_term_memory' => 'Bisher: X.']);
        $message = $project->addMessage('Hallo', $owner);
        $project->update(['summarized_until_message_id' => $message->id]);

        Livewire::test(EditProject::class, ['project' => $project])
            ->set('title', 'Neuer Titel')
            ->call('save')
            ->assertHasNoErrors();

        $project->refresh();

        $this->assertSame('Neuer Titel', $project->title);
        $this->assertSame('Bisher: X.', $project->long_term_memory);
        $this->assertSame($message->id, $project->summarized_until_message_id);
    }

    public function test_pipeline_and_model_are_frozen_once_the_run_has_started(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $project = Project::factory()->create([
            'user_id' => $owner->id,
            'model' => 'openai',
            'run_config' => ['pipeline' => 'RoundRobinPipeline'],
        ]);

        Livewire::test(EditProject::class, ['project' => $project])
            ->assertSet('runStarted', true)
            ->set('model', 'gemini')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('openai', $project->fresh()->model);
    }

    public function test_pipeline_and_model_can_change_before_the_first_turn(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $project = Project::factory()->create(['user_id' => $owner->id, 'model' => 'openai']);

        Livewire::test(EditProject::class, ['project' => $project])
            ->set('model', 'gemini')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('gemini', $project->fresh()->model);
    }
}
