<?php

namespace Tests\Feature;

use App\Jobs\Dependencies\ProjectJob;
use App\Jobs\MessageGenerator;
use App\Livewire\Projects\ControlChat;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

class AutoplayControlChatTest extends TestCase
{
    use RefreshDatabase;

    private function makeProject(array $settings = []): Project
    {
        $owner = User::factory()->create();

        return Project::withoutEvents(fn () => Project::create([
            'title' => 't', 'description' => 'd', 'settings' => $settings, 'user_id' => $owner->id,
        ]));
    }

    public function test_toggle_autoplay_persists_to_settings(): void
    {
        $project = $this->makeProject();
        $this->actingAs($project->owner);

        Livewire::test(ControlChat::class, ['project' => $project])
            ->call('toggleAutoplay')
            ->assertSet('autoplay', true);

        $this->assertTrue($project->fresh()->settings['autoplay']);

        Livewire::test(ControlChat::class, ['project' => $project])
            ->call('toggleAutoplay')
            ->assertSet('autoplay', false);

        $this->assertFalse($project->fresh()->settings['autoplay']);
    }

    public function test_send_message_rearms_generation_when_autoplay_on(): void
    {
        Bus::fake();
        Event::fake();

        $project = $this->makeProject(['autoplay' => true]);
        $this->actingAs($project->owner);

        Livewire::test(ControlChat::class, ['project' => $project])
            ->set('msgContent', 'Was haltet ihr von diesem Ansatz?')
            ->call('sendMessage');

        Bus::assertDispatched(MessageGenerator::class);
        $this->assertTrue(ProjectJob::isGenerating($project->id));
    }

    public function test_send_message_does_not_rearm_when_autoplay_off(): void
    {
        Bus::fake();
        Event::fake();

        $project = $this->makeProject(['autoplay' => false]);
        $this->actingAs($project->owner);

        Livewire::test(ControlChat::class, ['project' => $project])
            ->set('msgContent', 'Was haltet ihr von diesem Ansatz?')
            ->call('sendMessage');

        Bus::assertNotDispatched(MessageGenerator::class);
        $this->assertFalse(ProjectJob::isGenerating($project->id));
    }
}
