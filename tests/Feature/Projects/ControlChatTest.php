<?php

namespace Tests\Feature\Projects;

use App\Discussion\GenerationLoop;
use App\Events\GenerationStarted;
use App\Events\GenerationStopped;
use App\Jobs\MessageGenerator;
use App\Livewire\Projects\ControlChat;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class ControlChatTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([GenerationStarted::class, GenerationStopped::class]);
        Bus::fake([MessageGenerator::class]);

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    private function loop(): GenerationLoop
    {
        return app(GenerationLoop::class);
    }

    private function chat(Project $project): Testable
    {
        return Livewire::test(ControlChat::class, ['project' => $project]);
    }

    /**
     * Pausing mid-turn leaves the start button disabled until that turn ends,
     * and the turn's own broadcasts go out while it still counts as running.
     * Nothing else would re-render the bar, so it polls while it waits.
     */
    public function test_a_bar_paused_mid_turn_polls_until_the_turn_ends(): void
    {
        $project = Project::factory()->create(['user_id' => $this->user->id]);
        $this->loop()->start($project->id);
        $chat = $this->chat($project);

        $this->loop()->withLock($project->id, function () use ($chat): void {
            $chat->call('stopGenerate')
                ->assertViewHas('showGenerate', true)
                ->assertViewHas('disableGenerate', true)
                ->assertSeeHtml('wire:poll.2s');
        });

        $chat->call('$refresh')
            ->assertViewHas('disableGenerate', false)
            ->assertDontSeeHtml('wire:poll.2s');
    }

    public function test_an_idle_bar_does_not_poll(): void
    {
        $project = Project::factory()->create(['user_id' => $this->user->id]);

        $this->chat($project)
            ->assertViewHas('disableGenerate', false)
            ->assertDontSeeHtml('wire:poll');
    }

    /**
     * A bundle can bring in a project with an empty description. TurnRunner
     * refuses to run it, so starting would fail silently in the queue; the bar
     * says why instead and starts nothing.
     */
    public function test_starting_a_project_without_a_topic_shows_a_notice_and_starts_nothing(): void
    {
        $project = Project::factory()->create(['user_id' => $this->user->id, 'description' => '  ']);

        $this->chat($project)
            ->call('startGenerate')
            ->assertSee(__('chat.controls.missing_topic'))
            ->assertViewHas('showGenerate', true);

        $this->assertFalse($this->loop()->isGenerating($project->id));
        Bus::assertNotDispatched(MessageGenerator::class);
        Event::assertNotDispatched(GenerationStarted::class);
    }

    public function test_starting_a_project_with_a_topic_starts_the_loop(): void
    {
        $project = Project::factory()->create(['user_id' => $this->user->id]);

        $this->chat($project)
            ->call('startGenerate')
            ->assertDontSee(__('chat.controls.missing_topic'));

        $this->assertTrue($this->loop()->isGenerating($project->id));
        Bus::assertDispatched(MessageGenerator::class);
    }
}
