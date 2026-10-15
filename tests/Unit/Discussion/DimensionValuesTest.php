<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Metrics\Questions\DimensionValues;
use App\Discussion\Pipelines\PipelineRegistry;
use App\Discussion\Values\Dimension;
use App\Discussion\Values\DimensionType;
use App\Models\Expert;
use App\Models\Project;
use App\Models\TagCategory;
use App\Models\TagScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DimensionValuesTest extends TestCase
{
    use RefreshDatabase;

    private function values(): DimensionValues
    {
        return app(DimensionValues::class);
    }

    public function test_tag_labels_follow_the_value_order_and_persona_tags_add_no_value(): void
    {
        $category = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'gender']);
        $f = $category->values()->create(['name' => 'f']);
        $m = $category->values()->create(['name' => 'm']);
        $m->update(['position' => 0]);
        $f->update(['position' => 1]);

        $labels = $this->values()->labels(new Dimension(DimensionType::PersonaTag, $category->id));

        $this->assertSame([$m->id, $f->id, DimensionValues::NONE], array_keys($labels));
        $this->assertSame('Gender: m', $labels[$m->id]);
        $this->assertSame('Gender: '.__('questions.result.no_value'), $labels[DimensionValues::NONE]);
    }

    public function test_pipeline_labels_come_from_the_registry(): void
    {
        $this->assertSame(
            app(PipelineRegistry::class)->options(),
            $this->values()->labels(new Dimension(DimensionType::Pipeline)),
        );
    }

    public function test_a_run_has_its_tag_pipeline_and_model(): void
    {
        $category = TagCategory::create(['scope' => TagScope::Project, 'name' => 'bedingung']);
        $status = $category->values()->create(['name' => 'status']);
        $project = Project::factory()->create(['pipeline' => 'ScorePipeline', 'model' => 'openai-gpt-5']);
        $untagged = Project::factory()->create();
        $project->setTag($category, $status);
        $byTag = new Dimension(DimensionType::ProjectTag, $category->id);

        $this->assertSame($status->id, $this->values()->ofProject($byTag, $project));
        $this->assertNull($this->values()->ofProject($byTag, $untagged));
        $this->assertSame('ScorePipeline', $this->values()->ofProject(new Dimension(DimensionType::Pipeline), $project));
        $this->assertSame('openai-gpt-5', $this->values()->ofProject(new Dimension(DimensionType::Model), $project));
    }

    public function test_agents_without_a_value_have_none(): void
    {
        $category = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'title-holder']);
        $true = $category->values()->create(['name' => 'true']);
        [$alex, $kim] = Expert::factory()->count(2)->create();
        $alex->setTag($category, $true);

        $this->assertSame(
            [$alex->id => $true->id, $kim->id => DimensionValues::NONE],
            $this->values()->ofExperts(new Dimension(DimensionType::PersonaTag, $category->id), [$alex->id, $kim->id]),
        );
    }

    /** A run without a value and a category label are read once, not once per question part. */
    public function test_repeated_lookups_are_answered_without_a_new_query(): void
    {
        $category = TagCategory::create(['scope' => TagScope::Project, 'name' => 'condition']);
        $dimension = new Dimension(DimensionType::ProjectTag, $category->id);
        $untagged = Project::factory()->create();
        $values = $this->values();

        $this->assertNull($values->ofProject($dimension, $untagged));
        $values->labels($dimension);

        DB::enableQueryLog();
        $this->assertNull($values->ofProject($dimension, $untagged));
        $values->labels($dimension);

        $this->assertEmpty(array_filter(
            DB::getQueryLog(),
            fn (array $query) => str_contains($query['query'], 'from "taggables"') || str_contains($query['query'], 'from "tag_categories"'),
        ));
    }
}
