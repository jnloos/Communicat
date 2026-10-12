<?php

namespace Tests\Feature\Statistics;

use App\Models\Question;
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

        $this->assertSame(6, Question::templates()->count());
        $this->assertTrue(Question::templates()->get()->every(fn (Question $template) => $template->user_id === $admin->id));
        $statusEffect = Question::templates()->where('name', 'Status effect on turn share by pipeline')->sole()->definition();
        $this->assertSame('pipeline', $statusEffect->splitBy->key());
        $this->assertSame(60, $statusEffect->turns->to);
    }

    public function test_a_template_whose_tags_are_missing_is_skipped(): void
    {
        User::factory()->create(['is_admin' => true]);
        TagCategory::create(['scope' => TagScope::Project, 'name' => 'status condition'])->values()->create(['name' => 'status']);

        $this->seed(ThesisTemplatesSeeder::class);

        $this->assertSame(['Speaking inequality with and without status by pipeline'], Question::templates()->pluck('name')->all());
    }

    public function test_every_template_gets_a_note_and_a_rerun_restores_it(): void
    {
        User::factory()->create(['is_admin' => true]);
        $this->studyTags();

        $this->seed(ThesisTemplatesSeeder::class);
        Question::templates()->update(['notes' => null]);
        $this->seed(ThesisTemplatesSeeder::class);

        $this->assertCount(6, Question::templates()->whereNotNull('notes')->get());
        $this->assertStringContainsString('no-status minus status', Question::templates()->where('name', 'Status effect on turn share by pipeline')->sole()->notes);
    }
}
