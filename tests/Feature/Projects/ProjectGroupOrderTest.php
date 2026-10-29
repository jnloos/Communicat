<?php

namespace Tests\Feature\Projects;

use App\Livewire\Projects\ProjectGroupDialogs;
use App\Livewire\Projects\ProjectNavigation;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** The sidebar's groups keep a manual order per user: drag (wire:sort) and Move up / Move down. */
class ProjectGroupOrderTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> the group names as the sidebar lists them */
    private function names(User $user): array
    {
        return $user->projectGroups()->pluck('name')->all();
    }

    /** @return array{0: User, 1: list<ProjectGroup>} a signed-in user with groups A, B, C in that order */
    private function userWithThreeGroups(): array
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return [$user, array_map(fn (string $name) => $user->projectGroups()->create(['name' => $name]), ['A', 'B', 'C'])];
    }

    public function test_new_groups_join_at_the_end_of_their_owners_list(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $first = $user->projectGroups()->create(['name' => 'A']);
        $theirs = $other->projectGroups()->create(['name' => 'X']);
        $second = $user->projectGroups()->create(['name' => 'B']);

        $this->assertSame([1, 2], [$first->position, $second->position]);
        $this->assertSame(1, $theirs->position, 'positions are counted per user');
    }

    public function test_creating_a_group_in_the_dialog_appends_it(): void
    {
        [$user] = $this->userWithThreeGroups();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $project->addContributingUser($user);

        Livewire::test(ProjectGroupDialogs::class)
            ->call('startNewGroup', $project->id)
            ->set('form.name', 'D')
            ->call('createGroup')
            ->assertHasNoErrors();

        $this->assertSame(['A', 'B', 'C', 'D'], $this->names($user));
        $this->assertSame(4, $user->projectGroups()->where('name', 'D')->value('position'));
    }

    public function test_dragging_a_group_moves_it_and_the_order_survives_a_reload(): void
    {
        [$user, [$a, $b, $c]] = $this->userWithThreeGroups();

        $component = Livewire::test(ProjectNavigation::class)->call('sortGroup', $c->id, 0);

        $this->assertSame(['C', 'A', 'B'], $component->viewData('groups')->pluck('name')->all());
        $this->assertSame(['C', 'A', 'B'], $this->names($user));
        $this->assertSame(['C', 'A', 'B'], Livewire::test(ProjectNavigation::class)->viewData('groups')->pluck('name')->all());

        Livewire::test(ProjectNavigation::class)->call('sortGroup', $c->id, 2);
        $this->assertSame(['A', 'B', 'C'], $this->names($user));
        $this->assertSame([1, 2, 3], $user->projectGroups()->pluck('position')->all());
    }

    public function test_move_up_and_down_shift_by_one_and_stop_at_the_ends(): void
    {
        [$user, [$a, $b, $c]] = $this->userWithThreeGroups();

        Livewire::test(ProjectNavigation::class)
            ->call('moveGroup', $b->id, 1);
        $this->assertSame(['A', 'C', 'B'], $this->names($user));

        Livewire::test(ProjectNavigation::class)
            ->call('moveGroup', $b->id, 1)
            ->call('moveGroup', $a->id, -1);
        $this->assertSame(['A', 'C', 'B'], $this->names($user));

        Livewire::test(ProjectNavigation::class)->call('moveGroup', $b->id, -1);
        $this->assertSame(['A', 'B', 'C'], $this->names($user));
    }

    public function test_the_menu_disables_move_up_at_the_top_and_move_down_at_the_bottom(): void
    {
        [$user] = $this->userWithThreeGroups();

        $html = Livewire::test(ProjectNavigation::class)->html();

        $this->assertSame(6, substr_count($html, 'moveGroup('), 'two items per group, three groups');
        // Move up of the first and move down of the last group are disabled, the other four are not.
        preg_match_all('/<button[^>]*?(disabled="disabled")?\s+wire:click="moveGroup\((\d+), (-?1)\)"/', $html, $items, PREG_SET_ORDER);
        $disabled = array_map(fn (array $item) => $item[2].':'.$item[3], array_filter($items, fn (array $item) => $item[1] !== ''));
        $first = $user->projectGroups()->first()->id;
        $last = $user->projectGroups()->get()->last()->id;
        $this->assertSame(["{$first}:-1", "{$last}:1"], array_values($disabled));
        $this->assertStringContainsString('wire:sort="sortGroup"', $html);
        $this->assertSame(3, substr_count($html, 'wire:sort:item='));
        $this->assertSame(3, substr_count($html, 'wire:sort:ignore'));
    }

    public function test_a_foreign_group_cannot_be_sorted_or_moved(): void
    {
        [$user] = $this->userWithThreeGroups();
        $stranger = User::factory()->create();
        $theirs = $stranger->projectGroups()->create(['name' => 'X']);
        $stranger->projectGroups()->create(['name' => 'Y']);

        Livewire::test(ProjectNavigation::class)
            ->call('sortGroup', $theirs->id, 1)
            ->assertStatus(404);
        Livewire::test(ProjectNavigation::class)
            ->call('moveGroup', $theirs->id, 1)
            ->assertStatus(404);

        $this->assertSame(['X', 'Y'], $stranger->projectGroups()->pluck('name')->all());
        $this->assertSame(['A', 'B', 'C'], $this->names($user));
    }

    public function test_sorting_one_users_groups_never_touches_anothers(): void
    {
        [$user, [$a]] = $this->userWithThreeGroups();
        $stranger = User::factory()->create();
        $stranger->projectGroups()->create(['name' => 'X']);
        $stranger->projectGroups()->create(['name' => 'Y']);

        Livewire::test(ProjectNavigation::class)->call('sortGroup', $a->id, 2);

        $this->assertSame(['B', 'C', 'A'], $this->names($user));
        $this->assertSame([1, 2], $stranger->projectGroups()->pluck('position')->all());
    }

    public function test_a_tag_filter_turns_sorting_off_in_the_view_and_on_the_server(): void
    {
        [$user, [$a, $b, $c]] = $this->userWithThreeGroups();
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'status condition']);
        $status = $condition->values()->create(['name' => 'status']);
        $project = Project::factory()->create(['user_id' => $user->id]);
        $project->addContributingUser($user);
        $project->setTag($condition, $status);
        $project->fileInGroupFor($user, $c->id);

        $filtered = Livewire::test(ProjectNavigation::class)->set('tagFilter', [(string) $status->id]);

        $this->assertStringNotContainsString('wire:sort=', $filtered->html());
        $this->assertStringNotContainsString('moveGroup(', $filtered->html());

        $filtered->call('sortGroup', $c->id, 0)->call('moveGroup', $c->id, -1);
        $this->assertSame(['A', 'B', 'C'], $this->names($user));

        $filtered->call('clearTagFilter');
        $this->assertStringContainsString('wire:sort="sortGroup"', $filtered->html());
        $filtered->call('sortGroup', $c->id, 0);
        $this->assertSame(['C', 'A', 'B'], $this->names($user));
    }

    public function test_the_ungrouped_list_stays_outside_the_sortable_groups(): void
    {
        [$user] = $this->userWithThreeGroups();
        $project = Project::factory()->create(['user_id' => $user->id, 'title' => 'Lose']);
        $project->addContributingUser($user);

        $html = Livewire::test(ProjectNavigation::class)->html();

        $sortable = strpos($html, 'wire:sort="sortGroup"');
        $this->assertNotFalse($sortable);
        // The last group item comes before the ungrouped heading, which no wire:sort:item wraps.
        $this->assertGreaterThan(strrpos($html, 'wire:sort:item='), strpos($html, __('groups.ungrouped')));
    }
}
