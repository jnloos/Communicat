<?php

namespace Tests\Feature\Pipeline;

use App\Models\Project;
use App\Models\User;
use App\Services\Clients\OpenAIClient;
use App\Services\PromptingPipeline\DiscussionPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class EmptyContributorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_pipeline_stops_gracefully_without_contributors(): void
    {
        $owner = User::factory()->create();
        $project = Project::withoutEvents(fn () => Project::create([
            'title' => 't', 'description' => 'd', 'settings' => [], 'user_id' => $owner->id,
        ]));

        $this->mock(OpenAIClient::class, function ($m) {
            $m->shouldReceive('sendFast')->andReturn('{}');
            $m->shouldReceive('sendSlow')->andReturn('');
            $m->shouldReceive('sendManySlow')->andReturn([]);
        });

        $result = (new DiscussionPipeline($project))->run();

        $this->assertTrue($result['stop']);
        $this->assertSame('no_candidates', $result['reason']);
    }
}
