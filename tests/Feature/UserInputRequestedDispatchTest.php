<?php

namespace Tests\Feature;

use App\Livewire\Projects\ControlChat;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UserInputRequestedDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_input_requested_dispatches_browser_event(): void
    {
        $owner = User::factory()->create();
        $project = Project::withoutEvents(fn() => Project::create([
            'title' => 't', 'description' => 'd', 'settings' => [], 'user_id' => $owner->id,
        ]));

        $this->actingAs($owner);
        Livewire::test(ControlChat::class, ['project' => $project])
            ->call('onUserInputRequested')
            ->assertSet('userInputRequested', true)
            ->assertDispatched('user-input-requested',
                projectId: $project->id,
            );
    }

    public function test_clearing_dispatches_cleared_event(): void
    {
        $owner = User::factory()->create();
        $project = Project::withoutEvents(fn() => Project::create([
            'title' => 't', 'description' => 'd', 'settings' => [], 'user_id' => $owner->id,
        ]));

        $this->actingAs($owner);
        Livewire::test(ControlChat::class, ['project' => $project])
            ->set('userInputRequested', true)
            ->set('msgContent', 'hallo')
            ->assertSet('userInputRequested', false)
            ->assertDispatched('user-input-cleared');
    }
}
