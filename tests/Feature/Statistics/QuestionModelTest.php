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

        $this->assertSame(['B', 'A'], Question::templates()->pluck('name')->all());
        $this->assertSame(['Q'], Question::notTemplates()->pluck('name')->all());
    }

    public function test_templates_are_ordered_among_all_templates_and_questions_per_owner(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $alice->questions()->create(['name' => 'Alice 1']);
        $second = $alice->questions()->create(['name' => 'Alice 2']);
        $bobs = $bob->questions()->create(['name' => 'Bob 1']);
        $alice->questions()->create(['name' => 'T1', 'is_template' => true]);
        $template = $bob->questions()->create(['name' => 'T2', 'is_template' => true]);

        $second->moveTo(0);
        $template->moveBy(-1);

        $this->assertSame(['Alice 2', 'Alice 1'], $alice->questions()->notTemplates()->pluck('name')->all());
        $this->assertSame(1, $bobs->fresh()->position);
        $this->assertSame(['T2', 'T1'], Question::templates()->pluck('name')->all());
    }

    public function test_move_by_stops_at_either_end(): void
    {
        $user = User::factory()->create();
        $top = $user->questions()->create(['name' => 'Top']);
        $bottom = $user->questions()->create(['name' => 'Bottom']);

        $top->moveBy(-1);
        $bottom->moveBy(5);

        $this->assertSame(['Top', 'Bottom'], $user->questions()->notTemplates()->pluck('name')->all());
    }
}
