<?php

namespace Tests\Unit\Tags;

use App\Models\Project;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Services\Tags\TagsByName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TagsByNameTest extends TestCase
{
    use RefreshDatabase;

    public function test_names_match_regardless_of_case_and_surrounding_space_like_the_templates(): void
    {
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'status condition']);
        $status = $condition->values()->create(['name' => 'status']);
        $project = Project::factory()->create();

        $skipped = app(TagsByName::class)->assign($project, ['Status Condition ' => 'STATUS']);

        $this->assertSame([], $skipped);
        $this->assertSame([$condition->id => $status->id], $project->tagSelection());
    }

    /** An import assigns tags to every project and expert; the categories are read once, not per model. */
    public function test_the_categories_of_a_scope_are_read_once_per_instance(): void
    {
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'status condition']);
        $condition->values()->create(['name' => 'status']);
        [$first, $second] = Project::factory()->count(2)->create()->all();
        $tags = app(TagsByName::class);

        $tags->assign($first, ['status condition' => 'status']);
        DB::enableQueryLog();
        $tags->assign($second, ['status condition' => 'status']);

        $this->assertEmpty(array_filter(
            DB::getQueryLog(),
            fn (array $query) => str_contains($query['query'], 'from "tag_categories"'),
        ));
    }
}
