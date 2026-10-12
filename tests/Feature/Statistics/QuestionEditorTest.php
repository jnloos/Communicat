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

    public function test_an_unnamed_question_is_headed_by_its_sentence(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $comparison = $user->comparisons()->create(['question' => ['metric' => 'word_share']]);

        Livewire::test(QuestionEditor::class, ['comparisonId' => $comparison->id])->assertSee('Word share · everyone');
    }

    public function test_a_template_or_another_users_question_cannot_be_edited(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $template = $user->comparisons()->create(['is_template' => true]);
        $foreign = User::factory()->create()->comparisons()->create([]);

        foreach ([$template, $foreign] as $comparison) {
            try {
                Livewire::test(QuestionEditor::class, ['comparisonId' => $comparison->id]);
                $this->fail('Expected a 404.');
            } catch (ModelNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
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
}
