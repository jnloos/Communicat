<?php

namespace Tests\Feature\Statistics;

use App\Discussion\Metrics\SeriesProjects;
use App\Discussion\Values\SeriesDefinition;
use App\Models\Project;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeriesProjectsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_accessible_projects_that_match_tags_pipeline_and_model(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'condition']);
        $status = $condition->values()->create(['name' => 'status']);

        $own = Project::factory()->create(['user_id' => $user->id, 'pipeline' => 'ScorePipeline', 'model' => 'openai-gpt-5-mini']);
        $shared = Project::factory()->create(['user_id' => $stranger->id, 'pipeline' => 'ScorePipeline', 'model' => 'openai-gpt-5-mini']);
        $shared->addContributingUser($user);
        $foreign = Project::factory()->create(['user_id' => $stranger->id, 'pipeline' => 'ScorePipeline', 'model' => 'openai-gpt-5-mini']);
        $otherPipeline = Project::factory()->create(['user_id' => $user->id, 'pipeline' => 'RoundRobinPipeline', 'model' => 'openai-gpt-5-mini']);

        $otherModel = Project::factory()->create(['user_id' => $user->id, 'pipeline' => 'ScorePipeline', 'model' => 'openai-gpt-5']);

        foreach ([$own, $otherModel, $shared, $foreign, $otherPipeline] as $project) {
            $project->setTag($condition, $status);
        }

        $series = SeriesDefinition::fromArray(['label' => 'x', 'projects' => ['tags' => [$condition->id => [$status->id]], 'pipeline' => 'ScorePipeline', 'model' => 'openai-gpt-5-mini']]);

        $this->assertSame([$own->id, $shared->id], app(SeriesProjects::class)->for($user, $series)->pluck('id')->all());
    }
}
