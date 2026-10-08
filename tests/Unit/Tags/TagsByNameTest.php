<?php

namespace Tests\Unit\Tags;

use App\Models\Project;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Services\Tags\TagsByName;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
