<?php

namespace Tests\Feature\Statistics;

use App\Discussion\Values\Question;
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
            'projects' => ['turn_budget' => 60],
            'compare_by' => "project_tag:{$condition->id}",
            'split_by' => 'pipeline',
        ]));

        $this->assertSame('Turn share · Focus persona: true · runs of 60 turns · by Status condition · per Pipeline', $name);
    }

    public function test_gini_names_no_people(): void
    {
        $this->assertNull(app(QuestionSentence::class)->parts(Question::fromArray(['metric' => 'turn_gini']))['people']);
    }
}
