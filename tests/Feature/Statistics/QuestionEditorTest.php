<?php

namespace Tests\Feature\Statistics;

use App\Livewire\Statistics\QuestionEditor;
use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Project;
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
}
