<?php

namespace Tests\Feature\Statistics;

use App\Discussion\Values\Question;
use App\Discussion\Values\QuestionResult;
use App\Discussion\Values\TurnRange;
use App\Livewire\Statistics\QuestionSentence;
use App\Models\TagCategory;
use App\Models\TagScope;
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

    public function test_an_empty_question_says_everyone_and_all_runs(): void
    {
        $parts = app(QuestionSentence::class)->parts(new Question);

        $this->assertSame('Turn share', $parts['metric']);
        $this->assertSame('everyone', $parts['people']);
        $this->assertSame('together', $parts['aggregate']);
        $this->assertSame('all runs', $parts['projects']);
        $this->assertSame('all turns', $parts['turns']);
        $this->assertNull($parts['compare_by']);
        $this->assertNull($parts['split_by']);
    }

    public function test_the_name_reads_the_whole_question(): void
    {
        $focus = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'focus persona']);
        $true = $focus->values()->create(['name' => 'true']);
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'status condition']);

        $name = app(QuestionSentence::class)->of(Question::fromArray([
            'people' => ['tags' => [$focus->id => [$true->id]]],
            'turns' => ['to' => 60],
            'compare_by' => "project_tag:{$condition->id}",
            'split_by' => 'pipeline',
        ]));

        $this->assertSame('Turn share · Focus persona: true · turns 1 to 60 · by Status condition · per Pipeline', $name);
    }

    public function test_an_unknown_tag_value_reads_as_deleted_not_empty(): void
    {
        $category = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'focus persona']);
        $true = $category->values()->create(['name' => 'true']);

        $parts = app(QuestionSentence::class)->parts(Question::fromArray([
            'people' => ['tags' => [$category->id => [$true->id, 9999]]],
        ]));

        $this->assertSame('Focus persona: true, deleted value', $parts['people']);
    }

    public function test_gini_names_no_people(): void
    {
        $this->assertNull(app(QuestionSentence::class)->parts(Question::fromArray(['metric' => 'turn_gini']))['people']);
    }

    private function turns(array $turns, ?QuestionResult $effective = null): string
    {
        return app(QuestionSentence::class)->parts(Question::fromArray(['turns' => $turns]), $effective)['turns'];
    }

    private function resultOver(TurnRange $turns, bool $capped): QuestionResult
    {
        return new QuestionResult([], [], 0, 0, $turns, $capped);
    }

    public function test_the_window_reads_as_a_range(): void
    {
        $this->assertSame('turns 3 to 9', $this->turns(['from' => 3, 'to' => 9]));
        $this->assertSame('turns 1 to 60', $this->turns(['to' => 60]));
    }

    public function test_an_open_window_names_only_where_it_starts(): void
    {
        $this->assertSame('from turn 4', $this->turns(['from' => 4]));
    }

    public function test_the_shortest_run_is_named_when_it_set_the_upper_bound(): void
    {
        $this->assertSame('turns 1 to 3 (shortest run)', $this->turns([], $this->resultOver(new TurnRange(1, 3), true)));
        $this->assertSame('turns 1 to 3', $this->turns(['to' => 3], $this->resultOver(new TurnRange(1, 3), false)));
    }

    public function test_a_name_stays_put_when_runs_come_and_go(): void
    {
        $sentence = app(QuestionSentence::class);

        $this->assertSame('Turn share · everyone', $sentence->of(new Question));
        $this->assertSame('Turn share · everyone · from turn 4', $sentence->of(Question::fromArray(['turns' => ['from' => 4]])));
    }

    public function test_a_start_beyond_the_shortest_run_says_why_the_answer_is_empty(): void
    {
        $result = new QuestionResult([], [], 2, 2, new TurnRange(4, 4), true, 3);

        $this->assertSame('from turn 4 (beyond the shortest run, 3 turns)', $this->turns(['from' => 4], $result));
    }
}
