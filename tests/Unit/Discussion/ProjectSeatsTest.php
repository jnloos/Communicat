<?php

namespace Tests\Unit\Discussion;

use App\Models\Expert;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectSeatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_experts_get_consecutive_seats_in_joining_order(): void
    {
        $project = Project::factory()->create();
        [$a, $b, $c] = Expert::factory()->count(3)->create()->all();

        $project->addContributingExpert($b);
        $project->addContributingExpert($a);
        $project->addContributingExpert($c);

        $seated = $project->contributingExperts();

        $this->assertSame([$b->id, $a->id, $c->id], $seated->pluck('id')->all());
        $this->assertSame([1, 2, 3], $seated->map(fn (Expert $e) => $e->pivot->seat)->all());
    }

    public function test_adding_the_same_expert_twice_keeps_the_seat(): void
    {
        $project = Project::factory()->create();
        $expert = Expert::factory()->create();

        $project->addContributingExpert($expert);
        $project->addContributingExpert($expert);

        $this->assertCount(1, $project->contributingExperts());
        $this->assertSame(1, $project->contributingExperts()->first()->pivot->seat);
    }

    public function test_new_projects_get_pipeline_model_and_seed_defaults(): void
    {
        $project = Project::factory()->create()->fresh();

        $this->assertSame('RoundRobinPipeline', $project->pipeline);
        $this->assertSame('openai', $project->model);
        $this->assertGreaterThan(0, $project->seed);
        $this->assertNull($project->turn_budget);
    }
}
