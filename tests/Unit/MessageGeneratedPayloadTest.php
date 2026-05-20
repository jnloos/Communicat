<?php

namespace Tests\Unit;

use App\Events\MessageGenerated;
use App\Models\Expert;
use App\Models\Message;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageGeneratedPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_payload_contains_speaker_and_addressed_ids(): void
    {
        $owner = User::factory()->create();
        $project = Project::withoutEvents(fn() => Project::create([
            'title' => 't', 'description' => 'd', 'settings' => [], 'user_id' => $owner->id,
        ]));
        $alice = Expert::factory()->create(['name' => 'Alice']);
        $bob = Expert::factory()->create(['name' => 'Bob']);
        $project->addContributingExpert($alice);
        $project->addContributingExpert($bob);

        $message = $project->addMessage('Hallo Bob.', $alice);
        $message->next_speaker = 'Bob';
        $message->save();

        $payload = (new MessageGenerated($project->id, $message->id))->broadcastWith();

        $this->assertSame($project->id, $payload['project_id']);
        $this->assertSame($message->id, $payload['message_id']);
        $this->assertSame($alice->id, $payload['expert_id']);
        $this->assertSame($bob->id, $payload['addressed_expert_id']);
    }

    public function test_payload_ignores_user_addressee(): void
    {
        $owner = User::factory()->create();
        $project = Project::withoutEvents(fn() => Project::create([
            'title' => 't', 'description' => 'd', 'settings' => [], 'user_id' => $owner->id,
        ]));
        $alice = Expert::factory()->create(['name' => 'Alice']);
        $project->addContributingExpert($alice);

        $message = $project->addMessage('Was meinst du?', $alice);
        $message->next_speaker = 'Nutzer';
        $message->save();

        $payload = (new MessageGenerated($project->id, $message->id))->broadcastWith();

        $this->assertNull($payload['addressed_expert_id']);
    }

    public function test_payload_handles_missing_message(): void
    {
        $owner = User::factory()->create();
        $project = Project::withoutEvents(fn() => Project::create([
            'title' => 't', 'description' => 'd', 'settings' => [], 'user_id' => $owner->id,
        ]));

        $payload = (new MessageGenerated($project->id))->broadcastWith();

        $this->assertNull($payload['message_id']);
        $this->assertNull($payload['expert_id']);
        $this->assertNull($payload['addressed_expert_id']);
    }
}
