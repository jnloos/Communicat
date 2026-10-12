<?php

namespace Tests\Feature\Statistics;

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
        $comparison = $user->comparisons()->create([]);

        Livewire::test(QuestionEditor::class, ['comparisonId' => $comparison->id])
            ->set('question.metric', 'turn_gini')
            ->set('question.compare_by', 'persona_tag:3')
            ->assertSet('question.compare_by', '');

        $this->assertSame('turn_gini', $comparison->fresh()->question['metric']);
        $this->assertSame('', $comparison->fresh()->question['compare_by']);
    }

    public function test_it_shows_the_sentence_the_answer_and_the_runs_behind_it(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->spokenRun($user);
        $comparison = $user->comparisons()->create([]);

        Livewire::test(QuestionEditor::class, ['comparisonId' => $comparison->id])
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
        $comparison = $user->comparisons()->create(['name' => 'My title', 'question' => ['metric' => 'word_share']]);

        Livewire::test(QuestionEditor::class, ['comparisonId' => $comparison->id])
            ->assertSee('My title');
    }

    public function test_an_untitled_question_says_so(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $comparison = $user->comparisons()->create(['question' => ['metric' => 'word_share']]);

        Livewire::test(QuestionEditor::class, ['comparisonId' => $comparison->id])
            ->assertSee('Untitled question');
    }

    public function test_another_users_own_question_cannot_be_opened(): void
    {
        $this->actingAs(User::factory()->create());
        $foreign = User::factory()->create()->comparisons()->create([]);

        $this->expectException(ModelNotFoundException::class);
        Livewire::test(QuestionEditor::class, ['comparisonId' => $foreign->id]);
    }

    public function test_a_fix_question_loads_read_only_and_never_saves(): void
    {
        $this->actingAs(User::factory()->create());
        $fix = User::factory()->create()->comparisons()->create(['name' => 'Fixed', 'question' => ['metric' => 'word_share'], 'is_template' => true]);

        Livewire::test(QuestionEditor::class, ['comparisonId' => $fix->id])
            ->assertSet('readOnly', true)
            ->assertSee('Fixed')
            ->assertSeeHtml('data-flux-callout')
            ->assertSee('This template is view only. Copy it to adjust.')
            ->assertSee('Copy to adjust')
            ->assertDontSee('Template, view only')
            ->set('question.metric', 'turn_gini');

        $this->assertSame('word_share', $fix->fresh()->question['metric']);
    }

    public function test_the_sentence_of_a_template_has_no_controls_and_a_question_has_them(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $fix = User::factory()->create()->comparisons()->create(['question' => ['metric' => 'word_share'], 'is_template' => true]);
        $own = $user->comparisons()->create([]);

        Livewire::test(QuestionEditor::class, ['comparisonId' => $fix->id])
            ->assertSee('Word share')
            ->assertDontSeeHtml('data-flux-dropdown');

        Livewire::test(QuestionEditor::class, ['comparisonId' => $own->id])
            ->assertSeeHtml('data-flux-dropdown')
            ->assertDontSee('This template is view only');
    }

    public function test_the_editor_does_not_repeat_the_sentence_under_the_title(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $own = $user->comparisons()->create([]);

        Livewire::test(QuestionEditor::class, ['comparisonId' => $own->id])
            ->assertDontSeeHtml('data-question-sentence');
    }

    public function test_a_fix_question_may_be_viewed_as_a_curve_without_saving(): void
    {
        $this->actingAs(User::factory()->create());
        $fix = User::factory()->create()->comparisons()->create(['question' => ['metric' => 'word_share'], 'is_template' => true]);

        Livewire::test(QuestionEditor::class, ['comparisonId' => $fix->id])
            ->set('question.form', 'trajectory')
            ->assertSet('question.form', 'trajectory')
            ->assertSet('question.metric', 'word_share');

        $this->assertNotSame('trajectory', $fix->fresh()->question['form'] ?? null);
    }

    public function test_an_own_question_stays_editable(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $comparison = $user->comparisons()->create([]);

        Livewire::test(QuestionEditor::class, ['comparisonId' => $comparison->id])
            ->assertSet('readOnly', false)
            ->assertDontSee('Copy to adjust')
            ->set('question.metric', 'word_share');

        $this->assertSame('word_share', $comparison->fresh()->question['metric']);
    }

    public function test_an_empty_answer_says_how_to_widen_it(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $comparison = $user->comparisons()->create([]);

        Livewire::test(QuestionEditor::class, ['comparisonId' => $comparison->id])->assertSee(__('questions.result.empty'));
    }

    public function test_a_deleted_tag_value_drops_out_and_the_user_is_told(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->spokenRun($user);
        $category = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'rolle']);
        $kept = $category->values()->create(['name' => 'kept']);
        $gone = $category->values()->create(['name' => 'gone']);
        $comparison = $user->comparisons()->create(['question' => ['people' => ['tags' => [$category->id => [$kept->id, $gone->id]]]]]);
        $gone->delete();

        Livewire::test(QuestionEditor::class, ['comparisonId' => $comparison->id])
            ->assertSet('question.people.tags', [$category->id => [$kept->id]])
            ->assertSet('droppedTagValues', 1)
            ->assertSee('no longer exists');

        $this->assertSame([$kept->id], $comparison->fresh()->question['people']['tags'][$category->id]);
    }

    public function test_a_category_left_without_values_still_filters_and_answers_nothing(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->spokenRun($user);
        $category = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'rolle']);
        $only = $category->values()->create(['name' => 'only']);
        $comparison = $user->comparisons()->create(['question' => ['people' => ['tags' => [$category->id => [$only->id]]]]]);
        $only->delete();

        Livewire::test(QuestionEditor::class, ['comparisonId' => $comparison->id])
            ->assertSet('question.people.tags', [$category->id => [$only->id]])
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
        $comparison = $user->comparisons()->create([]);

        Livewire::test(QuestionEditor::class, ['comparisonId' => $comparison->id])
            ->set('question.compare_by', "persona_tag:{$category->id}")
            ->set('question.form', 'composition')
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
        $comparison = $user->comparisons()->create([]);

        Livewire::test(QuestionEditor::class, ['comparisonId' => $comparison->id])
            ->set('question.compare_by', "persona_tag:{$category->id}")
            ->set('question.form', 'trajectory')
            ->assertSet('question.form', 'trajectory')
            ->assertSee('ceo')
            ->assertSee('staff')
            ->assertDontSee('A curve needs at least two spoken turns.');
    }

    public function test_the_turn_window_is_normalised_and_saved(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $comparison = $user->comparisons()->create([]);

        Livewire::test(QuestionEditor::class, ['comparisonId' => $comparison->id])
            ->set('question.turns.from', '5')
            ->set('question.turns.to', '2')
            ->assertSet('question.turns.from', 5)
            ->assertSet('question.turns.to', 5);

        $this->assertSame(['from' => 5, 'to' => 5], $comparison->fresh()->question['turns']);
    }

    public function test_the_sentence_shows_the_bound_the_shortest_run_sets(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->spokenRun($user);
        $comparison = $user->comparisons()->create([]);

        Livewire::test(QuestionEditor::class, ['comparisonId' => $comparison->id])
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
        $comparison = $user->comparisons()->create([]);

        Livewire::test(QuestionEditor::class, ['comparisonId' => $comparison->id])
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

        $comparison = $user->comparisons()->create(['question' => ['turns' => ['from' => 2]]]);

        Livewire::test(QuestionEditor::class, ['comparisonId' => $comparison->id])
            ->assertSeeHtml('placeholder="2"')
            ->assertSeeHtml('placeholder="4"');
    }

    public function test_every_listed_category_names_its_source_as_a_badge_never_in_parentheses(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        TagCategory::create(['scope' => TagScope::Project, 'name' => 'status condition']);
        $persona = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'title-holder']);
        $comparison = $user->comparisons()->create(['name' => 'q', 'question' => ['compare_by' => "persona_tag:{$persona->id}"]]);

        Livewire::test(QuestionEditor::class, ['comparisonId' => $comparison->id])
            ->assertSee('Project tag')
            ->assertSee('Expert tag')
            ->assertSee('Run setting')
            ->assertSee('Title-holder')
            ->assertDontSee('Persona title-holder')
            ->assertDontSee('(Expert tag)')
            ->assertDontSee('(Project tag)');
    }
}
