<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Metrics\Questions\QuestionRuns;
use App\Discussion\Values\ProjectFilter;
use App\Discussion\Values\TagFilter;
use App\Models\Project;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuestionRunsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_users_runs_narrowed_by_every_part_of_the_filter(): void
    {
        $user = User::factory()->create();
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'bedingung']);
        $status = $condition->values()->create(['name' => 'status']);

        $match = Project::factory()->create(['user_id' => $user->id, 'pipeline' => 'ScorePipeline', 'turn_budget' => 60]);
        $match->setTag($condition, $status);
        $otherBudget = Project::factory()->create(['user_id' => $user->id, 'pipeline' => 'ScorePipeline', 'turn_budget' => 100]);
        $otherBudget->setTag($condition, $status);
        Project::factory()->create(['user_id' => $user->id, 'pipeline' => 'ScorePipeline', 'turn_budget' => 60]);
        $foreign = Project::factory()->create(['pipeline' => 'ScorePipeline', 'turn_budget' => 60]);
        $foreign->setTag($condition, $status);

        $runs = app(QuestionRuns::class)->for($user, new ProjectFilter(
            tags: new TagFilter([$condition->id => [$status->id]]),
            pipelines: ['ScorePipeline', 'QualityPipeline'],
            models: ['openai-gpt-5'],
            turnBudget: 60,
        ));

        $this->assertSame([$match->id], $runs->pluck('id')->all());
    }

    public function test_an_empty_filter_reads_every_run_the_user_may_read(): void
    {
        $user = User::factory()->create();
        Project::factory()->count(2)->create(['user_id' => $user->id]);
        Project::factory()->create();

        $this->assertCount(2, app(QuestionRuns::class)->for($user, new ProjectFilter));
    }
}
