<?php

namespace Tests\Feature\Statistics;

use App\Discussion\Values\Metric;
use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuestionModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_question_holds_one_question_and_may_have_no_name(): void
    {
        $question = User::factory()->create()->questions()->create(['definition' => ['metric' => 'word_share']]);

        $this->assertNull($question->fresh()->name);
        $this->assertSame(Metric::WordShare, $question->fresh()->definition()->metric);
        $this->assertFalse($question->fresh()->is_template);
    }

    public function test_a_missing_question_reads_as_the_default_one(): void
    {
        $question = User::factory()->create()->questions()->create([]);

        $this->assertSame(Metric::TurnShare, $question->fresh()->definition()->metric);
    }

    public function test_templates_and_questions_are_kept_apart(): void
    {
        $user = User::factory()->create();
        $user->questions()->create(['name' => 'B', 'is_template' => true]);
        $user->questions()->create(['name' => 'A', 'is_template' => true]);
        $user->questions()->create(['name' => 'Q']);

        $this->assertSame(['A', 'B'], Question::templates()->pluck('name')->all());
        $this->assertSame(['Q'], Question::notTemplates()->pluck('name')->all());
    }
}
