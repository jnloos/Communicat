<?php

namespace Tests\Feature\Loading;

use App\Livewire\Experts\ExpertList;
use App\Livewire\Projects\CreateProject;
use App\Livewire\Projects\SelectContributors;
use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LoadingStatesDTest extends TestCase
{
    use RefreshDatabase;

    public function test_expert_grid_dims_while_search_or_tag_filter_loads(): void
    {
        $this->actingAs(User::factory()->create());
        Expert::factory()->create();

        Livewire::test(ExpertList::class)
            ->assertSeeHtml('wire:target="search,tagFilter,tagFilterMode,clearTagFilter"');
    }

    public function test_contributor_cards_dim_while_their_action_runs(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        Expert::factory()->create();
        $this->actingAs($user);

        Livewire::test(SelectContributors::class, ['project' => $project])
            ->assertSeeHtml('wire:target="addExpert,removeExpert,search,tagFilter,tagFilterMode,clearTagFilter"');
    }

    public function test_model_select_dims_while_provider_reloads_it(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateProject::class)->assertSeeHtml('wire:target="form.provider"');
    }
}
