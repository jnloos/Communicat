<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Metrics\Questions\QuestionRuns;
use App\Discussion\Values\ProjectFilter;
use App\Discussion\Values\TagFilter;
use App\Models\Project;
use App\Models\ProjectGroup;
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

        $match = Project::factory()->create(['user_id' => $user->id, 'pipeline' => 'ScorePipeline', 'turn_budget' => 100]);
        $match->setTag($condition, $status);
        Project::factory()->create(['user_id' => $user->id, 'pipeline' => 'ScorePipeline', 'turn_budget' => 100]);
        $foreign = Project::factory()->create(['pipeline' => 'ScorePipeline', 'turn_budget' => 100]);
        $foreign->setTag($condition, $status);

        $runs = app(QuestionRuns::class)->for($user, new ProjectFilter(
            tags: new TagFilter([$condition->id => [$status->id]]),
            pipelines: ['ScorePipeline', 'QualityPipeline'],
            models: ['openai-gpt-5'],
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

    public function test_a_group_filter_reads_only_what_this_user_filed_in_one_of_the_groups(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $score = $user->projectGroups()->create(['name' => 'Score', 'position' => 0]);
        $quality = $user->projectGroups()->create(['name' => 'Quality', 'position' => 1]);
        $theirs = $other->projectGroups()->create(['name' => 'Score', 'position' => 0]);

        $inScore = $this->fileRun($user, $score);
        $inQuality = $this->fileRun($user, $quality);
        $this->fileRun($user, null);
        $shared = $this->fileRun($user, null);
        $shared->addContributingUser($other);
        $shared->fileInGroupFor($other, $theirs->id);

        $runs = app(QuestionRuns::class);

        $this->assertSame([$inScore->id, $inQuality->id], $runs->for($user, new ProjectFilter(groups: [$score->id, $quality->id]))->pluck('id')->all());
        $this->assertSame([$inScore->id], $runs->for($user, new ProjectFilter(groups: [$score->id]))->pluck('id')->all());
        // Another user's group, and one that is gone, match nothing: never every run.
        $this->assertCount(0, $runs->for($user, new ProjectFilter(groups: [$theirs->id])));
        $this->assertCount(0, $runs->for($user, new ProjectFilter(groups: [9999])));
    }

    private function fileRun(User $user, ?ProjectGroup $group): Project
    {
        $project = Project::factory()->create(['user_id' => $user->id]);
        $project->addContributingUser($user);
        $project->fileInGroupFor($user, $group?->id);

        return $project;
    }
}
