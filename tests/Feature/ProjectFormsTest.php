<?php

namespace Tests\Feature;

use App\Discussion\Values\AttributeVisibility;
use App\Livewire\Projects\CreateProject;
use App\Livewire\Projects\EditProject;
use App\Models\JobLog;
use App\Models\PersonaAttribute;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProjectFormsTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_project_stores_pipeline_model_owner_and_a_welcome_message(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(CreateProject::class)
            ->assertSet('form.pipeline', 'RoundRobinPipeline')
            ->set('form.title', 'KI an Schulen')
            ->set('form.description', 'Sollen Schulen KI-Werkzeuge erlauben?')
            ->set('form.provider', 'anthropic')
            ->set('form.model', 'anthropic-opus-5')
            ->set('form.summarizeThreshold', '30')
            ->set('form.summarizeOldest', '10')
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
            ->set('form.title', 'x')
            ->set('form.description', 'y')
            ->set('form.pipeline', 'NopePipeline')
            ->set('form.model', 'nope')
            ->call('save')
            ->assertHasErrors(['form.pipeline', 'form.model']);
    }

    public function test_creating_a_project_outside_a_request_needs_no_logged_in_user(): void
    {
        $owner = User::factory()->create();

        $project = Project::create(['title' => 'Lauf 1', 'description' => 'Thema', 'user_id' => $owner->id]);

        $this->assertSame($owner->id, $project->user_id);
        $this->assertSame(0, $project->messages()->count());
    }

    /** The description is the topic; without it the prompt has none, and a run refuses to start. */
    public function test_editing_cannot_empty_the_description(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $project = Project::factory()->create(['user_id' => $owner->id, 'description' => 'Handyverbot an Schulen?']);

        Livewire::test(EditProject::class, ['project' => $project])
            ->set('form.description', '')
            ->call('save')
            ->assertHasErrors(['form.description' => 'required']);

        $this->assertSame('Handyverbot an Schulen?', $project->fresh()->description);
    }

    public function test_editing_keeps_the_long_term_memory(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $project = Project::factory()->create(['user_id' => $owner->id, 'long_term_memory' => 'Bisher: X.']);
        $message = $project->addMessage('Hallo', $owner);
        $project->update(['summarized_until_message_id' => $message->id]);

        Livewire::test(EditProject::class, ['project' => $project])
            ->set('form.title', 'Neuer Titel')
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
            ->set('form.provider', 'gemini')
            ->set('form.model', 'gemini-3.8-flash')
            ->call('save')
            ->assertHasNoErrors();

        $project->refresh();

        $this->assertSame('gemini-3.8-flash', $project->model);
        // The original snapshot keys stay put beside the trail.
        $this->assertSame('RoundRobinPipeline', $project->run_config['pipeline']);
        $this->assertSame(['key' => 'openai'], $project->run_config['model']);
        $this->assertSame([[
            'after_turn' => 7,
            'field' => 'model',
            'from' => 'openai-gpt-5',
            'to' => 'gemini-3.8-flash',
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
            ->set('form.title', 'Anderer Titel')
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
            ->set('form.pipeline', 'RoundRobinPipeline')
            ->set('form.provider', 'gemini')
            ->set('form.model', 'gemini-3.8-flash')
            ->call('save')
            ->assertHasNoErrors();

        $changes = $project->fresh()->run_config['changes'];

        $this->assertSame(['pipeline', 'model'], array_column($changes, 'field'));
        $this->assertSame(['GonePipeline', 'openai-gpt-5'], array_column($changes, 'from'));
        $this->assertSame(['RoundRobinPipeline', 'gemini-3.8-flash'], array_column($changes, 'to'));
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
            ->set('form.provider', 'gemini')
            ->set('form.model', 'gemini-3.8-flash')
            ->call('save');

        Livewire::test(EditProject::class, ['project' => $project->fresh()])
            ->set('form.provider', 'anthropic')
            ->set('form.model', 'anthropic-opus-5')
            ->call('save');

        $changes = $project->fresh()->run_config['changes'];

        $this->assertSame(['openai-gpt-5', 'gemini-3.8-flash'], array_column($changes, 'from'));
        $this->assertSame(['gemini-3.8-flash', 'anthropic-opus-5'], array_column($changes, 'to'));
    }

    public function test_the_summarize_settings_must_leave_a_history_behind(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $project = Project::factory()->create(['user_id' => $owner->id]);

        Livewire::test(EditProject::class, ['project' => $project])
            ->set('form.summarizeThreshold', '20')
            ->set('form.summarizeOldest', '20')
            ->call('save')
            ->assertHasErrors(['form.summarizeOldest' => 'lt']);

        Livewire::test(EditProject::class, ['project' => $project])
            ->set('form.summarizeThreshold', '1')
            ->set('form.summarizeOldest', '0')
            ->call('save')
            ->assertHasErrors(['form.summarizeThreshold' => 'min', 'form.summarizeOldest' => 'min']);

        Livewire::test(EditProject::class, ['project' => $project])
            ->set('form.summarizeThreshold', 'viele')
            ->call('save')
            ->assertHasErrors(['form.summarizeThreshold' => 'integer']);

        $this->assertNull($project->fresh()->summarize_threshold);
    }

    public function test_editing_stores_the_summarize_settings(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $project = Project::factory()->create(['user_id' => $owner->id]);

        Livewire::test(EditProject::class, ['project' => $project])
            ->assertSet('form.summarizeThreshold', (string) config('discussion.summarize_threshold'))
            ->assertSet('form.summarizeOldest', (string) config('discussion.summarize_oldest'))
            ->set('form.summarizeThreshold', '60')
            ->set('form.summarizeOldest', '30')
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
            ->set('form.provider', 'gemini')
            ->set('form.model', 'gemini-3.8-flash')
            ->call('save')
            ->assertHasNoErrors();

        $project->refresh();

        $this->assertSame('gemini-3.8-flash', $project->model);
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
            ->set('form.summarizeThreshold', '60')
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
        // The form opens on the configured default, whichever that currently is --
        // naming it here would tie this test to an unrelated config change.
        Livewire::test(CreateProject::class)
            ->assertSet('form.provider', 'openai')
            ->assertSet('form.model', config('ai.default_model'))
            ->set('form.provider', 'gemini')
            ->assertSet('form.model', 'gemini-3.8-flash');
    }

    public function test_a_model_that_does_not_belong_to_the_chosen_provider_is_rejected(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        // The dropdowns cannot produce this pair; only a forged request can. The
        // validation checks the model against its provider's own entries, so the
        // project cannot end up claiming a provider it does not run on.
        Livewire::test(CreateProject::class)
            ->set('form.title', 'Titel')
            ->set('form.description', 'Beschreibung')
            ->set('form.provider', 'openai')
            ->set('form.model', 'gemini-3.8-flash')
            ->call('save')
            ->assertHasErrors('form.model');
    }

    public function test_creating_a_project_stores_the_attribute_visibility_and_defaults_to_none(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateProject::class)
            ->assertSet('form.attributeVisibility', AttributeVisibility::None)
            ->set('form.title', 'A')
            ->set('form.description', 'B')
            ->set('form.attributeVisibility', 'all')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(AttributeVisibility::All, Project::sole()->attribute_visibility);
    }

    public function test_an_unknown_attribute_visibility_reads_as_none(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateProject::class)
            ->set('form.title', 'A')
            ->set('form.description', 'B')
            ->set('form.attributeVisibility', 'nope')
            ->assertSet('form.attributeVisibility', AttributeVisibility::None)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(AttributeVisibility::None, Project::sole()->attribute_visibility);
    }

    public function test_the_attribute_visibility_can_change_before_the_first_turn(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $project = Project::factory()->create(['user_id' => $owner->id]);

        Livewire::test(EditProject::class, ['project' => $project])
            ->assertSet('form.attributeVisibility', AttributeVisibility::None)
            ->set('form.attributeVisibility', 'own')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(AttributeVisibility::Own, $project->fresh()->attribute_visibility);
    }

    public static function visibilityTexts(): array
    {
        return [
            'en' => ['en', 'What the prompts say about attributes', 'No attributes in the prompt', 'Only the persona itself', 'All participants see everyone\'s attributes', 'the blind judge never does'],
            'de' => ['de', 'Was die Prompts über Attribute sagen', 'Keine Attribute im Prompt', 'Nur die Persona selbst', 'Alle Teilnehmer sehen die Attribute aller', 'der blinde Judge nie'],
        ];
    }

    #[DataProvider('visibilityTexts')]
    public function test_the_three_visibility_options_are_spelled_out(string $locale, string ...$texts): void
    {
        app()->setLocale($locale);
        $this->actingAs(User::factory()->create());
        PersonaAttribute::factory()->count(2)->create();

        $component = Livewire::test(CreateProject::class);
        foreach ($texts as $text) {
            $component->assertSee($text);
        }
        $component->assertSeeHtml('data-attributes-count')
            ->assertSee($locale === 'en' ? '2 attributes are defined.' : 'Es sind 2 Attribute definiert.')
            ->assertDontSee('data-attributes-none', false);
    }

    public function test_without_defined_attributes_the_accordion_shows_only_a_hint(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateProject::class)
            ->assertSeeHtml('data-attributes-none')
            ->assertSee(__('projects.attributes.settings_link'))
            ->assertDontSeeHtml('<a href="'.route('settings.attributes').'"')
            ->assertDontSee(__('projects.attribute_visibility.own.label'))
            ->assertDontSeeHtml('data-attributes-count')
            ->assertSet('form.attributeVisibility', AttributeVisibility::None);

        $this->actingAs(User::factory()->create(['is_admin' => true]));

        Livewire::test(CreateProject::class)
            ->assertSeeHtml('<a href="'.route('settings.attributes').'"');
    }

    public function test_the_attribute_visibility_is_locked_once_the_run_has_started(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        PersonaAttribute::factory()->create();
        $project = Project::factory()->create([
            'user_id' => $owner->id,
            'attribute_visibility' => 'none',
            'run_config' => ['pipeline' => 'RoundRobinPipeline'],
        ]);

        Livewire::test(EditProject::class, ['project' => $project])
            ->assertSee(__('projects.fields.attribute_visibility_locked'))
            ->assertSeeHtmlInOrder(['form.attributeVisibility', 'disabled'])
            ->assertSet('form.attributesOpen', false)
            ->set('form.attributeVisibility', 'all')
            ->call('save')
            ->assertHasErrors(['form.attributeVisibility'])
            ->assertSet('form.attributesOpen', true);

        $project = $project->fresh();
        $this->assertSame(AttributeVisibility::None, $project->attribute_visibility);
        $this->assertArrayNotHasKey('changes', $project->run_config);
    }

    public function test_saving_other_fields_after_the_start_leaves_the_visibility_and_the_log_alone(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $project = Project::factory()->create([
            'user_id' => $owner->id,
            'attribute_visibility' => 'own',
            'run_config' => ['pipeline' => 'RoundRobinPipeline'],
        ]);

        Livewire::test(EditProject::class, ['project' => $project])
            ->set('form.title', 'Neu')
            ->call('save')
            ->assertHasNoErrors();

        $project = $project->fresh();
        $this->assertSame('Neu', $project->title);
        $this->assertSame(AttributeVisibility::Own, $project->attribute_visibility);
        $this->assertArrayNotHasKey('changes', $project->run_config);
    }
}
