<?php

namespace Tests\Feature;

use App\Jobs\MessageGenerator;
use App\Livewire\Projects\ControlChat;
use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

class MinExpertsControlChatTest extends TestCase
{
    use RefreshDatabase;

    private function makeProject(int $expertCount, array $settings = []): Project
    {
        $owner = User::factory()->create();
        $project = Project::withoutEvents(fn () => Project::create([
            'title' => 't', 'description' => 'd', 'settings' => $settings, 'user_id' => $owner->id,
        ]));

        foreach (Expert::factory()->count($expertCount)->create() as $expert) {
            $project->addContributingExpert($expert);
        }

        return $project;
    }

    public function test_start_is_blocked_below_minimum_experts(): void
    {
        Bus::fake();
        Event::fake();

        $project = $this->makeProject(Project::MIN_CONTRIBUTING_EXPERTS - 1);
        $this->actingAs($project->owner);

        Livewire::test(ControlChat::class, ['project' => $project])
            ->call('startGenerate')
            ->assertSet('controlWarning', fn ($v) => is_string($v) && str_contains($v, (string) Project::MIN_CONTRIBUTING_EXPERTS));

        Bus::assertNotDispatched(MessageGenerator::class);
    }

    public function test_start_is_allowed_at_minimum_experts(): void
    {
        Bus::fake();
        Event::fake();

        $project = $this->makeProject(Project::MIN_CONTRIBUTING_EXPERTS);
        $this->actingAs($project->owner);

        Livewire::test(ControlChat::class, ['project' => $project])
            ->call('startGenerate')
            ->assertSet('controlWarning', null);

        Bus::assertDispatched(MessageGenerator::class);
    }

    public function test_demo_project_is_read_only(): void
    {
        Bus::fake();
        Event::fake();

        // Enough experts, but flagged as the shared demo → still blocked.
        $project = $this->makeProject(Project::MIN_CONTRIBUTING_EXPERTS, ['is_demo' => true]);
        $this->actingAs($project->owner);

        Livewire::test(ControlChat::class, ['project' => $project])
            ->call('startGenerate')
            ->assertSet('controlWarning', fn ($v) => is_string($v) && str_contains($v, 'schreibgeschützt'));

        Bus::assertNotDispatched(MessageGenerator::class);
    }
}
