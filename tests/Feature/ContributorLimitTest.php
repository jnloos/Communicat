<?php

namespace Tests\Feature;

use App\Events\ContributorsChanged;
use App\Livewire\Projects\SelectContributors;
use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

class ContributorLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([ContributorsChanged::class]);
    }

    public function test_can_add_up_to_limit(): void
    {
        $user = User::factory()->create();
        $project = Project::withoutEvents(fn() => Project::create([
            'title' => 't', 'description' => 'd', 'settings' => [], 'user_id' => $user->id,
        ]));

        $experts = Expert::factory()->count(Project::MAX_CONTRIBUTING_EXPERTS)->create();
        foreach ($experts as $e) {
            $project->addContributingExpert($e);
        }

        $this->assertSame(Project::MAX_CONTRIBUTING_EXPERTS, $project->experts()->count());
        $this->assertFalse($project->canAddExpert());
    }

    public function test_cannot_add_beyond_limit_via_component(): void
    {
        $user = User::factory()->create();
        $project = Project::withoutEvents(fn() => Project::create([
            'title' => 't', 'description' => 'd', 'settings' => [], 'user_id' => $user->id,
        ]));

        $experts = Expert::factory()->count(Project::MAX_CONTRIBUTING_EXPERTS)->create();
        foreach ($experts as $e) {
            $project->addContributingExpert($e);
        }
        $extra = Expert::factory()->create();

        $this->actingAs($user);
        Livewire::test(SelectContributors::class, ['project' => $project])
            ->call('addExpert', $extra->id)
            ->assertSet('limitWarning', fn($v) => is_string($v) && str_contains($v, '5'));

        $this->assertFalse(
            $project->experts()->whereKey($extra->id)->exists(),
            'Extra expert must not be attached when limit is reached.'
        );
    }

    public function test_removing_clears_warning_and_allows_add(): void
    {
        $user = User::factory()->create();
        $project = Project::withoutEvents(fn() => Project::create([
            'title' => 't', 'description' => 'd', 'settings' => [], 'user_id' => $user->id,
        ]));

        $experts = Expert::factory()->count(Project::MAX_CONTRIBUTING_EXPERTS)->create();
        foreach ($experts as $e) {
            $project->addContributingExpert($e);
        }

        $this->actingAs($user);
        $component = Livewire::test(SelectContributors::class, ['project' => $project])
            ->call('removeExpert', $experts->first()->id)
            ->assertSet('limitWarning', null);

        $this->assertTrue($project->fresh()->canAddExpert());
    }
}
