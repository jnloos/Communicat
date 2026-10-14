<?php

namespace Tests\Feature\Statistics;

use App\Discussion\Values\QuestionDefinition;
use App\Discussion\Values\QuestionResult;
use App\Discussion\Values\TurnRange;
use App\Livewire\Statistics\QuestionSentence;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuestionSentenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    public function test_two_splits_read_as_one_phrase_in_their_order(): void
    {
        $seat = TagCategory::create(['scope' => TagScope::Project, 'name' => 'seat test']);

        $parts = app(QuestionSentence::class)->parts(QuestionDefinition::fromArray(['split_by' => ['pipeline', "project_tag:{$seat->id}"]]));

        $this->assertSame('Pipeline, Seat test', $parts['split_by']);
    }

    public function test_an_empty_question_says_everyone_and_all_runs(): void
    {
        $parts = app(QuestionSentence::class)->parts(new QuestionDefinition);

        $this->assertSame('Turn share', $parts['metric']);
        $this->assertSame('everyone', $parts['people']);
        $this->assertSame('together', $parts['aggregate']);
        $this->assertSame('all runs', $parts['projects']);
        $this->assertSame('over all turns', $parts['turns']);
        $this->assertNull($parts['compare_by']);
        $this->assertNull($parts['split_by']);
    }

    public function test_the_name_reads_the_whole_question(): void
    {
        $focus = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'focus persona']);
        $true = $focus->values()->create(['name' => 'true']);
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'status condition']);

        $name = app(QuestionSentence::class)->of(QuestionDefinition::fromArray([
            'people' => ['tags' => [$focus->id => [$true->id]]],
            'turns' => ['to' => 60],
            'compare_by' => "project_tag:{$condition->id}",
            'split_by' => 'pipeline',
        ]));

        $this->assertSame('Turn share of Focus persona: true over turns 1 to 60, compared by Status condition, split by Pipeline', $name);
    }

    public function test_an_unknown_tag_value_reads_as_deleted_not_empty(): void
    {
        $category = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'focus persona']);
        $true = $category->values()->create(['name' => 'true']);

        $parts = app(QuestionSentence::class)->parts(QuestionDefinition::fromArray([
            'people' => ['tags' => [$category->id => [$true->id, 9999]]],
        ]));

        $this->assertSame('Focus persona: true, deleted value', $parts['people']);
    }

    public function test_the_people_label_lists_the_expert_names(): void
    {
        $parts = app(QuestionSentence::class)->parts(QuestionDefinition::fromArray(['people' => ['expert_names' => ['Alex Brandt', 'Kim Hofer']]]));

        $this->assertSame('Alex Brandt, Kim Hofer', $parts['people']);
    }

    public function test_gini_names_no_people(): void
    {
        $this->assertNull(app(QuestionSentence::class)->parts(QuestionDefinition::fromArray(['metric' => 'turn_gini']))['people']);
    }

    private function turns(array $turns, ?QuestionResult $effective = null): string
    {
        return app(QuestionSentence::class)->parts(QuestionDefinition::fromArray(['turns' => $turns]), $effective)['turns'];
    }

    private function resultOver(TurnRange $turns, bool $capped): QuestionResult
    {
        return new QuestionResult([], [], 0, 0, $turns, $capped);
    }

    public function test_the_window_reads_as_a_range(): void
    {
        $this->assertSame('over turns 3 to 9', $this->turns(['from' => 3, 'to' => 9]));
        $this->assertSame('over turns 1 to 60', $this->turns(['to' => 60]));
    }

    public function test_an_open_window_names_only_where_it_starts(): void
    {
        $this->assertSame('from turn 4 onwards', $this->turns(['from' => 4]));
    }

    public function test_the_german_window_reads_in_den_turns_and_ab(): void
    {
        app()->setLocale('de');

        $this->assertSame('in den Turns 1 bis 60', $this->turns(['to' => 60]));
        $this->assertSame('ab Turn 5', $this->turns(['from' => 5]));
        $this->assertSame('in allen Turns', $this->turns([]));
        $this->assertSame('Turn-Anteil von allen ab Turn 5', app(QuestionSentence::class)->of(QuestionDefinition::fromArray(['turns' => ['from' => 5]])));
    }

    public function test_a_bound_set_by_the_shortest_run_reads_like_any_other(): void
    {
        $this->assertSame('over turns 1 to 3', $this->turns([], $this->resultOver(new TurnRange(1, 3), true)));
        $this->assertSame('over turns 1 to 3', $this->turns(['to' => 3], $this->resultOver(new TurnRange(1, 3), false)));
    }

    public function test_a_name_stays_put_when_runs_come_and_go(): void
    {
        $sentence = app(QuestionSentence::class);

        $this->assertSame('Turn share of everyone', $sentence->of(new QuestionDefinition));
        $this->assertSame('Turn share of everyone from turn 4 onwards', $sentence->of(QuestionDefinition::fromArray(['turns' => ['from' => 4]])));
    }

    public function test_the_sentence_names_per_head_and_leaves_gini_without_people(): void
    {
        $sentence = app(QuestionSentence::class);

        $this->assertSame('Turn share of everyone per head', $sentence->of(QuestionDefinition::fromArray(['aggregate' => 'per_head'])));
        $this->assertSame('Gini of turns', $sentence->of(QuestionDefinition::fromArray(['metric' => 'turn_gini'])));
    }

    public function test_a_start_beyond_the_shortest_run_says_why_the_answer_is_empty(): void
    {
        $result = new QuestionResult([], [], 2, 2, new TurnRange(4, 4), true, 3);

        $this->assertSame('from turn 4, beyond the shortest run of 3 turns', $this->turns(['from' => 4], $result));
    }

    public function test_a_persona_tag_dimension_reads_as_its_category_and_has_no_source_in_the_sentence(): void
    {
        $focus = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'title-holder']);

        $parts = app(QuestionSentence::class)->parts(QuestionDefinition::fromArray([
            'compare_by' => "persona_tag:{$focus->id}",
            'split_by' => 'pipeline',
        ]));

        $this->assertSame('Title-holder', $parts['compare_by']);
        $this->assertSame('Pipeline', $parts['split_by']);
        $this->assertArrayNotHasKey('compare_by_source', $parts);
    }

    public function test_the_sentence_names_the_groups_of_the_user_and_the_group_dimension(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $score = $user->projectGroups()->create(['name' => 'Score', 'position' => 0]);
        $quality = $user->projectGroups()->create(['name' => 'Quality', 'position' => 1]);
        $sentence = app(QuestionSentence::class);

        $question = QuestionDefinition::fromArray(['projects' => ['groups' => [$score->id, $quality->id, 9999]], 'compare_by' => 'group']);

        $this->assertSame('Score, Quality, deleted group', $sentence->parts($question)['projects']);
        $this->assertSame('Group', $sentence->parts($question)['compare_by']);
        $this->assertSame('Turn share of everyone in Score, Quality, deleted group, compared by Group', $sentence->of($question));
    }
}
