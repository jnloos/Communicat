<?php

namespace Tests\Unit;

use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromptIdTokenTest extends TestCase
{
    use RefreshDatabase;

    private function project(): array
    {
        $owner = User::factory()->create();
        $project = Project::withoutEvents(fn () => Project::create([
            'title' => 't', 'description' => 'd', 'user_id' => $owner->id,
        ]));
        $alice = Expert::factory()->create(['name' => 'Alice']);
        $project->addContributingExpert($alice);
        $project->addContributingUser($owner);

        return [$project, $alice, $owner];
    }

    public function test_prompt_id_accessors_are_type_prefixed(): void
    {
        $alice = Expert::factory()->create();
        $user = User::factory()->create();

        $this->assertSame("E{$alice->id}", $alice->promptId);
        $this->assertSame("U{$user->id}", $user->promptId);
    }

    public function test_contributor_by_prompt_id_resolves_expert_and_user(): void
    {
        [$project, $alice, $owner] = $this->project();

        $this->assertTrue($project->contributorByPromptId("E{$alice->id}")->is($alice));
        $this->assertTrue($project->contributorByPromptId("U{$owner->id}")->is($owner));
    }

    public function test_contributor_by_prompt_id_rejects_unknown_or_malformed(): void
    {
        [$project] = $this->project();

        $this->assertNull($project->contributorByPromptId('E999999'));
        $this->assertNull($project->contributorByPromptId('Alice'));
        $this->assertNull($project->contributorByPromptId(null));
        $this->assertNull($project->contributorByPromptId('X7'));
    }
}
