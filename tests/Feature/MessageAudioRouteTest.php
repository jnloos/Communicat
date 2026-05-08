<?php

namespace Tests\Feature;

use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use App\Services\ElevenLabsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MessageAudioRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_blocks_access_for_non_contributors(): void
    {
        Storage::fake('local');

        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id]);
        $project->addContributingUser($owner);

        $expert = Expert::factory()->create(['voice_id' => 'voice-a']);
        $project->addContributingExpert($expert);
        $message = $project->addMessage('Expert answer', $expert);

        $this->actingAs($outsider)
            ->get(route('messages.audio', ['message' => $message]))
            ->assertForbidden();
    }

    public function test_it_generates_and_caches_audio_for_authorized_users(): void
    {
        Storage::fake('local');

        config()->set('apis.elevenlabs.default_voice', 'fallback-voice');

        $owner = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id]);
        $project->addContributingUser($owner);

        $expert = Expert::factory()->create(['voice_id' => 'voice-a']);
        $project->addContributingExpert($expert);
        $message = $project->addMessage('**Test** message', $expert);

        $counter = (object) ['calls' => 0];
        $this->app->bind(ElevenLabsClient::class, function () use ($counter) {
            return new class ($counter) {
                public function __construct(private object $counter) {}

                public function synthesize(string $text, string $voiceId): string
                {
                    $this->counter->calls++;

                    return "audio:{$voiceId}:{$text}";
                }
            };
        });

        $this->actingAs($owner)
            ->get(route('messages.audio', ['message' => $message]))
            ->assertOk()
            ->assertHeader('Content-Type', 'audio/mpeg');

        Storage::disk('local')->assertExists("voice/messages/{$message->id}.mp3");
        $this->assertSame(1, $counter->calls);

        $this->actingAs($owner)
            ->get(route('messages.audio', ['message' => $message]))
            ->assertOk();

        $this->assertSame(1, $counter->calls);
    }
}
