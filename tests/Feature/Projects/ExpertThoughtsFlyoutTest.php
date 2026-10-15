<?php

namespace Tests\Feature\Projects;

use App\Livewire\Projects\ExpertThoughtsFlyout;
use App\Models\Expert;
use App\Models\Project;
use App\Models\Summary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ExpertThoughtsFlyoutTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Expert $expert;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->project = Project::factory()->create();
        $this->expert = Expert::factory()->create(['name' => 'Alice']);
        $this->project->addContributingExpert($this->expert);
    }

    /** The rolling thought is prose, so it is shown as written, line breaks and all. */
    public function test_the_thought_is_shown_as_plain_text(): void
    {
        Summary::create([
            'project_id' => $this->project->id,
            'expert_id' => $this->expert->id,
            'content' => "Ich halte das Verbot für zu pauschal.\n[E7] ist anderer Meinung.",
        ]);

        Livewire::test(ExpertThoughtsFlyout::class, ['project' => $this->project])
            ->call('openFor', $this->expert->id)
            ->assertSee('Ich halte das Verbot für zu pauschal.')
            ->assertSee('[E7] ist anderer Meinung.');
    }

    /** Opening the flyout only reads: it must not write an empty short-term memory. */
    public function test_an_expert_without_a_thought_shows_the_empty_state_and_writes_nothing(): void
    {
        Livewire::test(ExpertThoughtsFlyout::class, ['project' => $this->project])
            ->call('openFor', $this->expert->id)
            ->assertSee(__('experts.memory.empty', ['name' => 'Alice']));

        $this->assertSame(0, Summary::count());
    }
}
