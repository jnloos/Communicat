<?php

namespace Tests\Feature\Statistics;

use App\Discussion\Pipelines\PipelineRegistry;
use App\Livewire\Statistics\QuestionEditor;
use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Project;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class QuestionEditorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    private function spokenRun(User $user): Project
    {
        $project = Project::factory()->create(['user_id' => $user->id, 'title' => 'School | with status | 60']);
        $expert = Expert::factory()->create(['name' => 'Alex Brandt']);
        $project->addContributingExpert($expert);
        JobLog::create(['job_class' => 'x', 'project_id' => $project->id, 'status' => 'success', 'started_at' => now(), 'turn_index' => 1, 'expert_id' => $expert->id, 'words' => 12]);

        return $project;
    }

    public function test_every_change_is_normalised_and_saved_at_once(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $question = $user->questions()->create([]);

        Livewire::test(QuestionEditor::class, ['questionId' => $question->id])
            ->set('definition.metric', 'turn_gini')
            ->set('definition.compare_by', 'persona_tag:3')
            ->assertSet('definition.compare_by', '');

        $this->assertSame('turn_gini', $question->fresh()->definition['metric']);
        $this->assertSame('', $question->fresh()->definition['compare_by']);
    }

    public function test_a_saved_change_tells_the_page_and_a_template_does_not(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $own = $user->questions()->create([]);
        $template = User::factory()->create()->questions()->create(['definition' => ['metric' => 'word_share'], 'is_template' => true]);

        Livewire::test(QuestionEditor::class, ['questionId' => $own->id])
            ->set('definition.metric', 'turn_gini')
            ->assertDispatched('question-saved');

        Livewire::test(QuestionEditor::class, ['questionId' => $template->id])
            ->set('definition.form', 'trajectory')
            ->assertNotDispatched('question-saved');
    }

    public function test_a_trigger_is_cut_with_an_ellipsis_and_carries_its_full_label_as_title(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $own = $user->questions()->create([]);
        $template = User::factory()->create()->questions()->create(['definition' => ['metric' => 'word_share'], 'is_template' => true]);

        foreach ([$own, $template] as $question) {
            $html = Livewire::test(QuestionEditor::class, ['questionId' => $question->id])->html();

            $this->assertMatchesRegularExpression('/<button[^>]*data-statistics-trigger[^>]*>/', $html);
            $this->assertMatchesRegularExpression('/<button(?=[^>]*title="Word share|[^>]*title="[^"]+")(?=[^>]*max-w-64)(?=[^>]*truncate)[^>]*data-statistics-trigger/', $html);
        }
    }

    public function test_it_shows_the_sentence_the_answer_and_the_runs_behind_it(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->spokenRun($user);
        $question = $user->questions()->create([]);

        Livewire::test(QuestionEditor::class, ['questionId' => $question->id])
            ->assertSee('Turn share')
            ->assertSee('everyone')
            ->assertSee('all runs')
            ->assertSee('100.0 %')
            ->assertSee('School | with status | 60')
            ->assertSee(route('project.show', Project::sole()));
    }

    public function test_the_head_shows_the_title(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $question = $user->questions()->create(['name' => 'My title', 'definition' => ['metric' => 'word_share']]);

        Livewire::test(QuestionEditor::class, ['questionId' => $question->id])
            ->assertSee('My title');
    }

    public function test_an_untitled_question_says_so(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $question = $user->questions()->create(['definition' => ['metric' => 'word_share']]);

        Livewire::test(QuestionEditor::class, ['questionId' => $question->id])
            ->assertSee('Untitled question');
    }

    public function test_another_users_own_question_cannot_be_opened(): void
    {
        $this->actingAs(User::factory()->create());
        $foreign = User::factory()->create()->questions()->create([]);

        $this->expectException(ModelNotFoundException::class);
        Livewire::test(QuestionEditor::class, ['questionId' => $foreign->id]);
    }

    public function test_a_template_loads_read_only_and_never_saves(): void
    {
        $this->actingAs(User::factory()->create());
        $template = User::factory()->create()->questions()->create(['name' => 'Fixed', 'definition' => ['metric' => 'word_share'], 'is_template' => true]);

        Livewire::test(QuestionEditor::class, ['questionId' => $template->id])
            ->assertSet('readOnly', true)
            ->assertSee('Fixed')
            ->assertSeeHtml('data-flux-callout')
            ->assertSee('This template is view only. Copy it to adjust.')
            ->assertSee('Copy to adjust')
            ->assertDontSee('Template, view only')
            ->set('definition.metric', 'turn_gini');

        $this->assertSame('word_share', $template->fresh()->definition['metric']);
    }

    public function test_a_template_shows_the_same_triggers_disabled_and_a_question_has_them_enabled(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $template = User::factory()->create()->questions()->create(['definition' => ['metric' => 'word_share'], 'is_template' => true]);
        $own = $user->questions()->create([]);

        Livewire::test(QuestionEditor::class, ['questionId' => $template->id])
            ->assertSee('Word share')
            ->assertSeeHtml('data-statistics-trigger')
            ->assertSeeHtml('disabled')
            ->assertDontSeeHtml('data-statistics-text-part');

        $html = Livewire::test(QuestionEditor::class, ['questionId' => $own->id])
            ->assertSeeHtml('data-statistics-trigger')
            ->assertDontSee('This template is view only')
            ->html();

        $this->assertDoesNotMatchRegularExpression('/<button[^>]*data-statistics-trigger[^>]*disabled/', $html);
    }

    public function test_the_editor_does_not_repeat_the_sentence_under_the_title(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $own = $user->questions()->create([]);

        Livewire::test(QuestionEditor::class, ['questionId' => $own->id])
            ->assertDontSeeHtml('data-question-sentence');
    }

    public function test_a_template_may_be_viewed_as_a_curve_without_saving(): void
    {
        $this->actingAs(User::factory()->create());
        $template = User::factory()->create()->questions()->create(['definition' => ['metric' => 'word_share'], 'is_template' => true]);

        Livewire::test(QuestionEditor::class, ['questionId' => $template->id])
            ->set('definition.form', 'trajectory')
            ->assertSet('definition.form', 'trajectory')
            ->assertSet('definition.metric', 'word_share');

        $this->assertNotSame('trajectory', $template->fresh()->definition['form'] ?? null);
    }

    public function test_an_own_question_stays_editable(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $question = $user->questions()->create([]);

        Livewire::test(QuestionEditor::class, ['questionId' => $question->id])
            ->assertSet('readOnly', false)
            ->assertDontSee('Copy to adjust')
            ->set('definition.metric', 'word_share');

        $this->assertSame('word_share', $question->fresh()->definition['metric']);
    }

    public function test_an_empty_answer_says_how_to_widen_it(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $question = $user->questions()->create([]);

        Livewire::test(QuestionEditor::class, ['questionId' => $question->id])->assertSee(__('questions.result.empty'));
    }

    public function test_a_deleted_tag_value_drops_out_and_the_user_is_told(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->spokenRun($user);
        $category = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'rolle']);
        $kept = $category->values()->create(['name' => 'kept']);
        $gone = $category->values()->create(['name' => 'gone']);
        $question = $user->questions()->create(['definition' => ['people' => ['tags' => [$category->id => [$kept->id, $gone->id]]]]]);
        $gone->delete();

        Livewire::test(QuestionEditor::class, ['questionId' => $question->id])
            ->assertSet('definition.people.tags', [$category->id => [$kept->id]])
            ->assertSet('droppedTagValues', 1)
            ->assertSee('no longer exists');

        $this->assertSame([$kept->id], $question->fresh()->definition['people']['tags'][$category->id]);
    }

    public function test_a_category_left_without_values_still_filters_and_answers_nothing(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->spokenRun($user);
        $category = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'rolle']);
        $only = $category->values()->create(['name' => 'only']);
        $question = $user->questions()->create(['definition' => ['people' => ['tags' => [$category->id => [$only->id]]]]]);
        $only->delete();

        Livewire::test(QuestionEditor::class, ['questionId' => $question->id])
            ->assertSet('definition.people.tags', [$category->id => [$only->id]])
            ->assertSee('deleted value')
            ->assertSee('No run answers this question');
    }

    /** A run whose two titled/untitled agents split the floor 3 : 1, and the persona category that tells them apart. */
    private function titledRun(User $user): TagCategory
    {
        $category = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'titel']);
        $project = Project::factory()->create(['user_id' => $user->id, 'title' => 'Run']);
        $titled = Expert::factory()->create(['name' => 'Chief']);
        $other = Expert::factory()->create(['name' => 'Peer']);
        $titled->setTag($category, $category->values()->create(['name' => 'ceo']));
        $other->setTag($category, $category->values()->create(['name' => 'staff']));

        foreach ([$titled, $other] as $expert) {
            $project->addContributingExpert($expert);
        }

        foreach ([$titled, $titled, $titled, $other] as $index => $expert) {
            JobLog::create(['job_class' => 'x', 'project_id' => $project->id, 'status' => 'success', 'started_at' => now(), 'turn_index' => $index + 1, 'expert_id' => $expert->id, 'words' => 10]);
        }

        return $category;
    }

    public function test_the_composition_form_shows_each_group_with_its_percentage(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $category = $this->titledRun($user);
        $question = $user->questions()->create([]);

        Livewire::test(QuestionEditor::class, ['questionId' => $question->id])
            ->set('definition.compare_by', "persona_tag:{$category->id}")
            ->set('definition.form', 'composition')
            ->assertSee('ceo')
            ->assertSee('staff')
            ->assertSee('75.0 %')
            ->assertSee('25.0 %');
    }

    public function test_the_trajectory_form_draws_a_curve_per_panel(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $category = $this->titledRun($user);
        $question = $user->questions()->create([]);

        Livewire::test(QuestionEditor::class, ['questionId' => $question->id])
            ->set('definition.compare_by', "persona_tag:{$category->id}")
            ->set('definition.form', 'trajectory')
            ->assertSet('definition.form', 'trajectory')
            ->assertSee('ceo')
            ->assertSee('staff')
            ->assertDontSee('A curve needs at least two spoken turns.');
    }

    public function test_the_turn_window_is_normalised_and_saved(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $question = $user->questions()->create([]);

        Livewire::test(QuestionEditor::class, ['questionId' => $question->id])
            ->set('definition.turns.from', '5')
            ->set('definition.turns.to', '2')
            ->assertSet('definition.turns.from', 5)
            ->assertSet('definition.turns.to', 5);

        $this->assertSame(['from' => 5, 'to' => 5], $question->fresh()->definition['turns']);
    }

    public function test_the_sentence_shows_the_bound_the_shortest_run_sets(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->spokenRun($user);
        $question = $user->questions()->create([]);

        Livewire::test(QuestionEditor::class, ['questionId' => $question->id])
            ->assertSee('over turns 1 to 1')
            ->assertSee('The upper bound is at most the shortest selected run, “', false)
            ->assertSee('School | with status | 60')
            ->assertSee('”, which has 1 turn.', false);
    }

    public function test_the_shortest_run_in_the_hint_links_to_its_chat(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $run = $this->spokenRun($user);
        $question = $user->questions()->create([]);

        Livewire::test(QuestionEditor::class, ['questionId' => $question->id])
            ->assertSeeHtml('href="'.route('project.show', $run->id).'"');
    }

    public function test_the_turn_fields_show_the_effective_window_as_placeholders(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $run = $this->spokenRun($user);
        $expert = $run->contributingExperts()->first();

        foreach ([2, 3, 4] as $turn) {
            JobLog::create(['job_class' => 'x', 'project_id' => $run->id, 'status' => 'success', 'started_at' => now(), 'turn_index' => $turn, 'expert_id' => $expert->id, 'words' => 12]);
        }

        $question = $user->questions()->create(['definition' => ['turns' => ['from' => 2]]]);

        Livewire::test(QuestionEditor::class, ['questionId' => $question->id])
            ->assertSeeHtml('placeholder="2"')
            ->assertSeeHtml('placeholder="4"');
    }

    public function test_every_listed_category_names_its_source_in_square_brackets_never_as_a_badge(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        TagCategory::create(['scope' => TagScope::Project, 'name' => 'status condition']);
        TagCategory::create(['scope' => TagScope::Persona, 'name' => 'title-holder']);
        $question = $user->questions()->create(['name' => 'q']);

        Livewire::test(QuestionEditor::class, ['questionId' => $question->id])
            ->assertSee('Title-holder [Expert]')
            ->assertSee('Status condition [Project]')
            ->assertSee('Pipeline [Run]')
            ->assertSee('Model [Run]')
            ->assertDontSee('Project tag')
            ->assertDontSee('Expert tag')
            ->assertDontSee('Run setting')
            ->assertDontSee('(Expert)')
            ->assertDontSeeHtml('data-flux-badge');
    }

    public function test_the_menus_set_the_measure_the_compare_dimension_and_the_split(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $category = TagCategory::create(['scope' => TagScope::Project, 'name' => 'status condition']);
        $question = $user->questions()->create([]);

        Livewire::test(QuestionEditor::class, ['questionId' => $question->id])
            ->set('definition.metric', 'word_share')
            ->set('definition.compare_by', "project_tag:{$category->id}")
            ->set('definition.split_by', 'pipeline')
            ->set('definition.aggregate', 'per_head');

        $saved = $question->fresh()->definition;
        $this->assertSame('word_share', $saved['metric']);
        $this->assertSame("project_tag:{$category->id}", $saved['compare_by']);
        $this->assertSame('pipeline', $saved['split_by']);
        $this->assertSame('per_head', $saved['aggregate']);
    }

    public function test_the_menu_checkboxes_save_tag_arrays_and_the_expert_name(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $persona = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'title-holder']);
        $yes = $persona->values()->create(['name' => 'true']);
        $project = TagCategory::create(['scope' => TagScope::Project, 'name' => 'status condition']);
        $status = $project->values()->create(['name' => 'status']);
        Expert::factory()->create(['name' => 'Alex Brandt']);
        $question = $user->questions()->create([]);

        Livewire::test(QuestionEditor::class, ['questionId' => $question->id])
            ->set("definition.people.tags.{$persona->id}", [(string) $yes->id])
            ->set("definition.projects.tags.{$project->id}", [(string) $status->id])
            ->set('definition.projects.pipelines', ['RoundRobinPipeline'])
            ->set('definition.people.expert_name', 'Alex Brandt');

        $saved = $question->fresh()->definition;
        $this->assertSame([$yes->id], $saved['people']['tags'][$persona->id]);
        $this->assertSame([$status->id], $saved['projects']['tags'][$project->id]);
        $this->assertSame(['RoundRobinPipeline'], $saved['projects']['pipelines']);
        $this->assertSame('Alex Brandt', $saved['people']['expert_name']);
    }

    public function test_the_menus_offer_the_users_groups_as_filter_and_dimension_and_save_the_choice(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $user->projectGroups()->create(['name' => 'Score', 'position' => 0]);
        $other = User::factory()->create();
        $other->projectGroups()->create(['name' => 'Secret drawer', 'position' => 0]);
        $group = $user->projectGroups()->first();
        $question = $user->questions()->create([]);

        Livewire::test(QuestionEditor::class, ['questionId' => $question->id])
            ->assertSee('Groups [Group]')
            ->assertSee('Group [Group]')
            ->assertSee('Score')
            ->assertDontSee('Secret drawer')
            ->set('definition.projects.groups', [(string) $group->id])
            ->set('definition.compare_by', 'group');

        $saved = $question->fresh()->definition;
        $this->assertSame([$group->id], $saved['projects']['groups']);
        $this->assertSame('group', $saved['compare_by']);
    }

    public function test_notes_are_trimmed_saved_and_tell_the_page(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $question = $user->questions()->create([]);

        Livewire::test(QuestionEditor::class, ['questionId' => $question->id])
            ->set('notes', "  Why the CEO speaks more.  \n")
            ->assertDispatched('question-saved')
            ->assertSet('notes', 'Why the CEO speaks more.');

        $this->assertSame('Why the CEO speaks more.', $question->fresh()->notes);
    }

    public function test_empty_notes_are_stored_as_null(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $question = $user->questions()->create(['notes' => 'Something']);

        Livewire::test(QuestionEditor::class, ['questionId' => $question->id])
            ->assertSet('notes', 'Something')
            ->set('notes', "   \n ");

        $this->assertNull($question->fresh()->notes);
    }

    public function test_a_template_shows_its_notes_in_a_disabled_field_and_never_saves_them(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $template = User::factory()->create()->questions()->create(['notes' => 'Read the delta as no-status minus status.', 'is_template' => true]);

        Livewire::test(QuestionEditor::class, ['questionId' => $template->id])
            ->assertSee('Question and notes')
            ->assertSet('notes', 'Read the delta as no-status minus status.')
            ->assertSeeHtml('disabled')
            ->set('notes', 'Overwritten')
            ->assertNotDispatched('question-saved');

        $this->assertSame('Read the delta as no-status minus status.', $template->fresh()->notes);
    }

    public function test_an_own_question_shows_the_notes_field(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $question = $user->questions()->create([]);

        Livewire::test(QuestionEditor::class, ['questionId' => $question->id])
            ->assertSee('Question and notes')
            ->assertSeeHtml('<textarea');
    }

    public function test_choosing_a_compare_dimension_sets_its_first_value_as_baseline_and_the_menu_offers_the_others(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $category = TagCategory::create(['scope' => TagScope::Project, 'name' => 'status condition']);
        $status = $category->values()->create(['name' => 'status', 'position' => 1]);
        $noStatus = $category->values()->create(['name' => 'no-status', 'position' => 2]);
        $question = $user->questions()->create([]);

        $editor = Livewire::test(QuestionEditor::class, ['questionId' => $question->id])
            ->set('definition.compare_by', "project_tag:{$category->id}")
            ->assertSet('definition.baseline', (string) $status->id)
            ->assertSee('Baseline')
            ->set('definition.baseline', (string) $noStatus->id);

        $this->assertSame((string) $noStatus->id, $question->fresh()->definition['baseline']);

        $editor->set('definition.compare_by', 'pipeline')->assertSet('definition.baseline', array_key_first(app(PipelineRegistry::class)->options()));
        $editor->set('definition.compare_by', '')->assertSet('definition.baseline', null)->assertDontSee('Baseline');
    }

    public function test_saving_the_notes_tells_the_list_but_does_not_answer_the_question_again(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $question = $user->questions()->create([]);

        $editor = Livewire::test(QuestionEditor::class, ['questionId' => $question->id])
            ->set('notes', '  A conclusion  ')
            ->assertDispatched('question-saved');

        $this->assertArrayNotHasKey('html', $editor->effects);
        $this->assertSame('A conclusion', $question->fresh()->notes);

        $editor->set('definition.metric', 'word_share');
        $this->assertArrayHasKey('html', $editor->effects);
    }

    public function test_a_template_viewed_in_another_form_still_drops_its_deleted_tag_values(): void
    {
        $this->actingAs(User::factory()->create());
        $category = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'rolle']);
        $kept = $category->values()->create(['name' => 'kept']);
        $gone = $category->values()->create(['name' => 'gone']);
        $template = User::factory()->create()->questions()->create(['definition' => ['people' => ['tags' => [$category->id => [$kept->id, $gone->id]]]], 'is_template' => true]);
        $gone->delete();

        Livewire::test(QuestionEditor::class, ['questionId' => $template->id])
            ->set('definition.form', 'trajectory')
            ->assertSet('definition.form', 'trajectory')
            ->assertSet('definition.people.tags', [$category->id => [$kept->id]]);

        $this->assertSame([$kept->id, $gone->id], $template->fresh()->definition['people']['tags'][$category->id]);
    }

    public function test_a_templates_disabled_triggers_keep_full_text_colour(): void
    {
        $this->actingAs(User::factory()->create());
        $template = User::factory()->create()->questions()->create(['is_template' => true]);

        $html = Livewire::test(QuestionEditor::class, ['questionId' => $template->id])->html();

        $this->assertMatchesRegularExpression('/<button(?=[^>]*disabled)(?=[^>]*disabled:opacity-100!)[^>]*data-statistics-trigger/', $html);
    }

    public function test_a_curve_draws_the_fair_share_as_a_dashed_line_and_says_so(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $project = $this->spokenRun($user);
        JobLog::create(['job_class' => 'x', 'project_id' => $project->id, 'status' => 'success', 'started_at' => now(), 'turn_index' => 2, 'expert_id' => Expert::sole()->id, 'words' => 8]);
        $question = $user->questions()->create(['definition' => ['form' => 'trajectory']]);

        Livewire::test(QuestionEditor::class, ['questionId' => $question->id])
            ->assertSeeHtml('stroke-dasharray="4 4"')
            ->assertSee('Dashed line: fair share');
    }
}
