<?php

namespace Tests\Feature\Statistics;

use App\Models\Question;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\TagValue;
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
        $titleHolder = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'title-holder']);
        $titleHolder->values()->create(['name' => 'true']);
        $titleHolder->values()->create(['name' => 'false']);
        TagCategory::create(['scope' => TagScope::Persona, 'name' => 'focus persona'])->values()->create(['name' => 'true']);
    }

    public function test_seven_templates_owned_by_the_first_admin_and_idempotent(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->studyTags();

        $this->seed(ThesisTemplatesSeeder::class);
        $this->seed(ThesisTemplatesSeeder::class);

        $this->assertSame(7, Question::templates()->count());
        $this->assertTrue(Question::templates()->get()->every(fn (Question $template) => $template->user_id === $admin->id));
        $statusEffect = Question::templates()->where('name', 'Status effect on turn share by pipeline')->sole()->definition();
        $this->assertSame('pipeline', $statusEffect->splitBy->key());
        $this->assertSame(60, $statusEffect->turns->to);
    }

    public function test_a_template_whose_tags_are_missing_is_skipped(): void
    {
        User::factory()->create(['is_admin' => true]);
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'status condition']);
        $condition->values()->create(['name' => 'status']);
        $condition->values()->create(['name' => 'no-status']);

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

        $this->assertCount(7, Question::templates()->whereNotNull('notes')->get());
        $this->assertStringContainsString('with status minus without', Question::templates()->where('name', 'Status effect on turn share by pipeline')->sole()->notes);
    }

    public function test_every_comparing_template_names_the_side_without_the_title_as_baseline(): void
    {
        User::factory()->create(['is_admin' => true]);
        $this->studyTags();
        $noStatus = TagValue::where('name', 'no-status')->sole();
        $notTitled = TagValue::where('name', 'false')->sole();

        $this->seed(ThesisTemplatesSeeder::class);

        $baselines = Question::templates()->get()->mapWithKeys(fn (Question $template) => [$template->name => $template->definition()->baseline])->all();
        $this->assertSame((string) $noStatus->id, $baselines['Status effect on turn share by pipeline']);
        $this->assertSame((string) $noStatus->id, $baselines['Status effect on word share by pipeline']);
        $this->assertSame((string) $noStatus->id, $baselines['Speaking inequality with and without status by pipeline']);
        $this->assertSame((string) $notTitled->id, $baselines['Title holder against the others by pipeline (per head)']);
        $this->assertSame((string) $notTitled->id, $baselines['How the floor divides between title holder and others']);
        $this->assertSame((string) $noStatus->id, $baselines['Status effect under the seeing and the blind judge']);
        $this->assertSame('QualityPipeline', $baselines['Seeing against blind judge']);
    }

    public function test_the_judge_status_effect_template_splits_the_two_judge_pipelines(): void
    {
        User::factory()->create(['is_admin' => true]);
        $this->studyTags();

        $this->seed(ThesisTemplatesSeeder::class);

        $definition = Question::templates()->where('name', 'Status effect under the seeing and the blind judge')->sole()->definition();
        $this->assertSame('pipeline', $definition->splitBy->key());
        $this->assertSame(['QualityPipeline', 'BlindQualityPipeline'], $definition->projects->pipelines);
        $this->assertTrue($definition->projects->tags->isEmpty(), 'Both status conditions are compared, so neither may be filtered out.');
    }
}
