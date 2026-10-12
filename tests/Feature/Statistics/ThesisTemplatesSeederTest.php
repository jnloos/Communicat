<?php

namespace Tests\Feature\Statistics;

use App\Models\Comparison;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\User;
use Database\Seeders\ThesisTemplatesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThesisTemplatesSeederTest extends TestCase
{
    use RefreshDatabase;

    private function studyTags(): void
    {
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'status condition']);
        $condition->values()->create(['name' => 'status']);
        $condition->values()->create(['name' => 'no-status']);
        TagCategory::create(['scope' => TagScope::Persona, 'name' => 'title-holder'])->values()->create(['name' => 'true']);
        TagCategory::create(['scope' => TagScope::Persona, 'name' => 'focus persona'])->values()->create(['name' => 'true']);
    }

    public function test_six_templates_owned_by_the_first_admin_and_idempotent(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->studyTags();

        $this->seed(ThesisTemplatesSeeder::class);
        $this->seed(ThesisTemplatesSeeder::class);

        $this->assertSame(6, Comparison::templates()->count());
        $this->assertTrue(Comparison::templates()->get()->every(fn (Comparison $template) => $template->user_id === $admin->id));
        $statusEffect = Comparison::templates()->where('name', 'Status effect on turn share by pipeline')->sole()->toQuestion();
        $this->assertSame('pipeline', $statusEffect->splitBy->key());
        $this->assertSame(60, $statusEffect->projects->turnBudget);
    }

    public function test_a_template_whose_tags_are_missing_is_skipped(): void
    {
        User::factory()->create(['is_admin' => true]);
        TagCategory::create(['scope' => TagScope::Project, 'name' => 'status condition'])->values()->create(['name' => 'status']);

        $this->seed(ThesisTemplatesSeeder::class);

        $this->assertSame(['Speaking inequality with and without status by pipeline'], Comparison::templates()->pluck('name')->all());
    }
}
