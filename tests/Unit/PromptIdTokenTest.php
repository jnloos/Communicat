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
        $project = Project::withoutEvents(fn() => Project::create([
            'title' => 't', 'description' => 'd', 'settings' => [], 'user_id' => $owner->id,
        ]));
        $alice = Expert::factory()->create(['name' => 'Alice']);
        $project->addContributingExpert($alice);
        $project->addContributingUser($owner);

        return [$project, $alice, $owner];
    }

    public function test_prompt_id_accessors_are_type_prefixed(): void
    {
        $alice = Expert::factory()->create();
        $user  = User::factory()->create();

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

    public function test_message_to_prompt_array_carries_token(): void
    {
        [$project, $alice, $owner] = $this->project();

        $expertMsg = $project->addMessage('Hi', $alice);
        $userMsg   = $project->addMessage('Hallo', $owner);

        $this->assertSame("E{$alice->id}", $expertMsg->toPromptArray()['prompt_id']);
        $this->assertSame("U{$owner->id}", $userMsg->toPromptArray()['prompt_id']);
    }

    public function test_hands_back_to_user_reflects_partner_type(): void
    {
        [$project, $alice, $owner] = $this->project();

        $toUser = $project->addMessage('Frage an dich', $alice);
        $toUser->adjacencyPartner()->associate($owner);
        $toUser->save();

        $toExpert = $project->addMessage('Antwort', $alice);
        $toExpert->adjacencyPartner()->associate($alice);
        $toExpert->save();

        $this->assertTrue($toUser->handsBackToUser());
        $this->assertFalse($toExpert->handsBackToUser());
    }
}
