<?php

namespace Tests\Feature;

use App\Livewire\Projects\CreateProject;
use App\Livewire\Projects\EditProject;
use App\Models\JobLog;
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
            ->set('provider', 'anthropic')
            ->set('model', 'anthropic-opus-5')
            ->set('summarizeThreshold', '30')
            ->set('summarizeOldest', '10')
            ->call('save')
            ->assertHasNoErrors();

        $project = Project::sole();

        $this->assertSame('RoundRobinPipeline', $project->pipeline);
        $this->assertSame('anthropic-opus-5', $project->model);
        $this->assertSame(30, $project->summarize_threshold);
        $this->assertSame(10, $project->summarize_oldest);
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

    public function test_a_mid_run_change_is_applied_and_recorded_in_the_snapshot(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $project = Project::factory()->create([
            'user_id' => $owner->id,
            'pipeline' => 'RoundRobinPipeline',
            'model' => 'openai-gpt-5',
            'run_config' => ['pipeline' => 'RoundRobinPipeline', 'model' => ['key' => 'openai']],
        ]);
        JobLog::create(['job_class' => 'X', 'project_id' => $project->id, 'status' => 'success', 'started_at' => now(), 'turn_index' => 7]);

        Livewire::test(EditProject::class, ['project' => $project])
            ->assertSet('runStarted', true)
            ->set('provider', 'gemini')
            ->set('model', 'gemini-2.5-pro')
            ->call('save')
            ->assertHasNoErrors();

        $project->refresh();

        $this->assertSame('gemini-2.5-pro', $project->model);
        // The original snapshot keys stay put beside the trail.
        $this->assertSame('RoundRobinPipeline', $project->run_config['pipeline']);
        $this->assertSame(['key' => 'openai'], $project->run_config['model']);
        $this->assertSame([[
            'after_turn' => 7,
            'field' => 'model',
            'from' => 'openai-gpt-5',
            'to' => 'gemini-2.5-pro',
        ]], $project->run_config['changes']);
    }

    public function test_a_mid_run_edit_that_changes_neither_pipeline_nor_model_records_nothing(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $project = Project::factory()->create([
            'user_id' => $owner->id,
            'run_config' => ['pipeline' => 'RoundRobinPipeline'],
        ]);

        Livewire::test(EditProject::class, ['project' => $project])
            ->set('title', 'Anderer Titel')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertArrayNotHasKey('changes', $project->fresh()->run_config);
    }

    public function test_a_mid_run_pipeline_switch_is_recorded_alongside_a_model_switch(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        // RoundRobinPipeline is the only registered pipeline, so the switch has to
        // start from a name the registry no longer offers to be a switch at all.
        $project = Project::factory()->create([
            'user_id' => $owner->id,
            'pipeline' => 'GonePipeline',
            'model' => 'openai-gpt-5',
            'run_config' => ['pipeline' => 'GonePipeline'],
        ]);

        Livewire::test(EditProject::class, ['project' => $project])
            ->set('pipeline', 'RoundRobinPipeline')
            ->set('provider', 'gemini')
            ->set('model', 'gemini-2.5-pro')
            ->call('save')
            ->assertHasNoErrors();

        $changes = $project->fresh()->run_config['changes'];

        $this->assertSame(['pipeline', 'model'], array_column($changes, 'field'));
        $this->assertSame(['GonePipeline', 'openai-gpt-5'], array_column($changes, 'from'));
        $this->assertSame(['RoundRobinPipeline', 'gemini-2.5-pro'], array_column($changes, 'to'));
        // No turn has run yet, so the switch lands after turn 0.
        $this->assertSame([0, 0], array_column($changes, 'after_turn'));
    }

    public function test_successive_mid_run_changes_each_get_their_own_entry(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $project = Project::factory()->create([
            'user_id' => $owner->id,
            'model' => 'openai-gpt-5',
            'run_config' => ['pipeline' => 'RoundRobinPipeline'],
        ]);

        Livewire::test(EditProject::class, ['project' => $project])
            ->set('provider', 'gemini')
            ->set('model', 'gemini-2.5-pro')
            ->call('save');

        Livewire::test(EditProject::class, ['project' => $project->fresh()])
            ->set('provider', 'anthropic')
            ->set('model', 'anthropic-opus-5')
            ->call('save');

        $changes = $project->fresh()->run_config['changes'];

        $this->assertSame(['openai-gpt-5', 'gemini-2.5-pro'], array_column($changes, 'from'));
        $this->assertSame(['gemini-2.5-pro', 'anthropic-opus-5'], array_column($changes, 'to'));
    }

    public function test_the_summarize_settings_must_leave_a_history_behind(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $project = Project::factory()->create(['user_id' => $owner->id]);

        Livewire::test(EditProject::class, ['project' => $project])
            ->set('summarizeThreshold', '20')
            ->set('summarizeOldest', '20')
            ->call('save')
            ->assertHasErrors(['summarizeOldest' => 'lt']);

        Livewire::test(EditProject::class, ['project' => $project])
            ->set('summarizeThreshold', '1')
            ->set('summarizeOldest', '0')
            ->call('save')
            ->assertHasErrors(['summarizeThreshold' => 'min', 'summarizeOldest' => 'min']);

        Livewire::test(EditProject::class, ['project' => $project])
            ->set('summarizeThreshold', 'viele')
            ->call('save')
            ->assertHasErrors(['summarizeThreshold' => 'integer']);

        $this->assertNull($project->fresh()->summarize_threshold);
    }

    public function test_editing_stores_the_summarize_settings(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $project = Project::factory()->create(['user_id' => $owner->id]);

        Livewire::test(EditProject::class, ['project' => $project])
            ->assertSet('summarizeThreshold', (string) config('discussion.summarize_threshold'))
            ->assertSet('summarizeOldest', (string) config('discussion.summarize_oldest'))
            ->set('summarizeThreshold', '60')
            ->set('summarizeOldest', '30')
            ->call('save')
            ->assertHasNoErrors();

        $project->refresh();

        $this->assertSame(60, $project->summarize_threshold);
        $this->assertSame(30, $project->summarize_oldest);
    }

    public function test_pipeline_and_model_can_change_before_the_first_turn(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $project = Project::factory()->create(['user_id' => $owner->id, 'model' => 'openai-gpt-5']);
        $this->assertNull($project->run_config);

        Livewire::test(EditProject::class, ['project' => $project])
            ->assertSet('runStarted', false)
            ->set('provider', 'gemini')
            ->set('model', 'gemini-2.5-pro')
            ->call('save')
            ->assertHasNoErrors();

        $project->refresh();

        $this->assertSame('gemini-2.5-pro', $project->model);
        // Nothing to contradict yet: no snapshot exists, so no change is recorded and
        // the first turn is still free to freeze the run as it actually starts.
        $this->assertNull($project->run_config);
    }

    public function test_a_mid_run_change_to_the_summarization_numbers_is_recorded(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        // Both columns null, so the effective values are the configured defaults.
        $project = Project::factory()->create([
            'user_id' => $owner->id,
            'summarize_threshold' => null,
            'summarize_oldest' => null,
            'run_config' => ['pipeline' => 'RoundRobinPipeline'],
        ]);
        JobLog::create(['job_class' => 'X', 'project_id' => $project->id, 'status' => 'success', 'started_at' => now(), 'turn_index' => 3]);

        Livewire::test(EditProject::class, ['project' => $project])
            ->set('summarizeThreshold', '60')
            ->call('save')
            ->assertHasNoErrors();

        $project->refresh();

        $this->assertSame(60, $project->summarize_threshold);
        // Unlike the model, these two leave no trail anywhere else: without this
        // entry a mid-run change to them would be invisible in the data.
        $this->assertSame([[
            'after_turn' => 3,
            'field' => 'summarize_threshold',
            'from' => (int) config('discussion.summarize_threshold'),
            'to' => 60,
        ]], $project->run_config['changes']);
    }

    public function test_writing_the_configured_value_into_a_null_column_records_nothing(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $project = Project::factory()->create([
            'user_id' => $owner->id,
            'summarize_threshold' => null,
            'summarize_oldest' => null,
            'run_config' => ['pipeline' => 'RoundRobinPipeline'],
        ]);
        JobLog::create(['job_class' => 'X', 'project_id' => $project->id, 'status' => 'success', 'started_at' => now(), 'turn_index' => 5]);

        // mount() pre-fills both fields with the effective values, so saving
        // unchanged turns the null columns into explicit ones.
        Livewire::test(EditProject::class, ['project' => $project])
            ->call('save')
            ->assertHasNoErrors();

        $project->refresh();

        $this->assertSame((int) config('discussion.summarize_threshold'), $project->summarize_threshold);
        // The stored column changed, the run's condition did not.
        $this->assertArrayNotHasKey('changes', $project->run_config);
    }

    public function test_switching_the_provider_moves_the_model_to_that_providers_first_one(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        // Without this the form would keep a model belonging to the old provider.
        Livewire::test(CreateProject::class)
            ->assertSet('provider', 'openai')
            ->assertSet('model', 'openai-gpt-5')
            ->set('provider', 'gemini')
            ->assertSet('model', 'gemini-2.5-pro');
    }

    public function test_a_model_that_does_not_belong_to_the_chosen_provider_is_rejected(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        // The dropdowns cannot produce this pair; only a forged request can. The
        // validation checks the model against its provider's own entries, so the
        // project cannot end up claiming a provider it does not run on.
        Livewire::test(CreateProject::class)
            ->set('title', 'Titel')
            ->set('description', 'Beschreibung')
            ->set('provider', 'openai')
            ->set('model', 'gemini-2.5-pro')
            ->call('save')
            ->assertHasErrors('model');
    }
}
