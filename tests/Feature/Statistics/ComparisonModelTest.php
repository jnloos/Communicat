<?php

namespace Tests\Feature\Statistics;

use App\Discussion\Values\Metric;
use App\Models\Comparison;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComparisonModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_comparison_holds_one_question_and_may_have_no_name(): void
    {
        $comparison = User::factory()->create()->comparisons()->create(['question' => ['metric' => 'word_share']]);

        $this->assertNull($comparison->fresh()->name);
        $this->assertSame(Metric::WordShare, $comparison->fresh()->toQuestion()->metric);
        $this->assertFalse($comparison->fresh()->is_template);
    }

    public function test_a_missing_question_reads_as_the_default_one(): void
    {
        $comparison = User::factory()->create()->comparisons()->create([]);

        $this->assertSame(Metric::TurnShare, $comparison->fresh()->toQuestion()->metric);
    }

    public function test_templates_and_questions_are_kept_apart(): void
    {
        $user = User::factory()->create();
        $user->comparisons()->create(['name' => 'B', 'is_template' => true]);
        $user->comparisons()->create(['name' => 'A', 'is_template' => true]);
        $user->comparisons()->create(['name' => 'Q']);

        $this->assertSame(['A', 'B'], Comparison::templates()->pluck('name')->all());
        $this->assertSame(['Q'], Comparison::questions()->pluck('name')->all());
    }
}
