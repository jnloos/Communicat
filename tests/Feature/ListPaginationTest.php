<?php

namespace Tests\Feature;

use App\Livewire\Experts\ExpertList;
use App\Livewire\Projects\ProjectNavigation;
use App\Livewire\Projects\SelectContributors;
use App\Models\Expert;
use App\Models\Project;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A study wave holds thousands of runs and hundreds of personas: every list
 * shows at most ten rows per page, filtered and searched in SQL.
 */
class ListPaginationTest extends TestCase
{
    use RefreshDatabase;

    private function projectsFor(User $user, int $count, string $prefix = 'Run'): array
    {
        return collect(range(1, $count))->map(function (int $i) use ($user, $prefix) {
            $project = Project::factory()->create(['user_id' => $user->id, 'title' => sprintf('%s %02d', $prefix, $i)]);
            $project->addContributingUser($user);
            $project->forceFill(['updated_at' => now()->subMinutes(100 - $i)])->saveQuietly();

            return $project;
        })->all();
    }

    private function ids(iterable $rows): array
    {
        return collect($rows)->pluck('id')->sort()->values()->all();
    }

    public function test_the_ungrouped_list_shows_ten_projects_and_pages_on(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $projects = $this->projectsFor($user, 25);

        $component = Livewire::test(ProjectNavigation::class);
        $first = $component->viewData('ungrouped');
        $this->assertCount(10, $first);
        $this->assertSame($projects[24]->id, $first->first()->id, 'newest first');

        $component->call('nextPage', 'ungrouped')->call('nextPage', 'ungrouped');
        $this->assertCount(5, $component->viewData('ungrouped'));
    }

    public function test_each_drawer_pages_on_its_own(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $group = $user->projectGroups()->create(['name' => 'Wave', 'position' => 0]);
        foreach ($this->projectsFor($user, 12) as $project) {
            $project->fileInGroupFor($user, $group->id);
        }
        $this->projectsFor($user, 3, 'Loose');

        $component = Livewire::test(ProjectNavigation::class)->call('nextPage', 'group-'.$group->id);

        $this->assertCount(2, $component->viewData('grouped')[$group->id]);
        $this->assertCount(3, $component->viewData('ungrouped'));
    }

    public function test_the_filter_applies_in_sql_still_pages_and_resets_to_page_one(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $category = TagCategory::create(['scope' => TagScope::Project, 'name' => 'wave']);
        $value = $category->values()->create(['name' => '1']);
        $tagged = $this->projectsFor($user, 15, 'Tagged');
        foreach ($tagged as $project) {
            $project->setTag($category, $value);
        }
        $this->projectsFor($user, 15, 'Other');

        $component = Livewire::test(ProjectNavigation::class)
            ->call('nextPage', 'ungrouped')
            ->set('tagFilter', [(string) $value->id]);

        $this->assertSame(1, $component->get('paginators')['ungrouped']);
        $this->assertCount(10, $component->viewData('ungrouped'));
        $this->assertSame(15, $component->viewData('matchCount'));

        $component->call('nextPage', 'ungrouped');
        $this->assertCount(5, $component->viewData('ungrouped'));
        $this->assertEmpty(array_diff($this->ids($component->viewData('ungrouped')->items()), $this->ids($tagged)));
    }

    /** Off its page the open project is pinned on top of the page shown. */
    public function test_the_open_project_stays_in_its_list_when_it_is_on_another_page(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $projects = $this->projectsFor($user, 25);
        $oldest = $projects[0];

        $rows = Livewire::test(ProjectNavigation::class, ['currentProjectId' => $oldest->id])->viewData('ungrouped');

        $this->assertSame($oldest->id, $rows->first()->id);
        $this->assertCount(11, $rows);
    }

    public function test_the_pager_names_its_position_and_sets_the_pinned_project_apart(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $projects = $this->projectsFor($user, 25);

        Livewire::test(ProjectNavigation::class, ['currentProjectId' => $projects[0]->id])
            ->assertViewHas('ungroupedPages', 3)
            ->assertViewHas('pinnedIn', 'ungrouped')
            ->assertSeeHtml('data-pinned-separator')
            ->assertSee('1 / 3')
            ->assertSee(__('groups.pager.next'))
            ->call('nextPage', 'ungrouped')
            ->assertSee('2 / 3');

        Livewire::test(ProjectNavigation::class, ['currentProjectId' => $projects[24]->id])
            ->assertViewHas('pinnedIn', null)
            ->assertDontSeeHtml('data-pinned-separator');
    }

    public function test_a_sidebar_render_reads_no_more_than_a_page_of_projects(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->projectsFor($user, 40);

        DB::enableQueryLog();
        Livewire::test(ProjectNavigation::class);
        $selects = collect(DB::getQueryLog())->pluck('query')
            ->filter(fn (string $sql) => str_contains($sql, 'from "projects"') && ! str_contains($sql, 'count(*)'));

        $this->assertNotEmpty($selects);
        $selects->each(fn (string $sql) => $this->assertMatchesRegularExpression('/limit \d+|"projects"\."id" = \?/', $sql));
    }

    public function test_the_expert_list_pages_and_searches_in_sql(): void
    {
        $this->actingAs(User::factory()->create());
        foreach (range(1, 25) as $i) {
            Expert::factory()->create(['name' => sprintf('Persona %02d', $i), 'description' => 'd']);
        }
        Expert::factory()->create(['name' => 'Zora Unique', 'description' => 'd']);

        $component = Livewire::test(ExpertList::class);
        $this->assertCount(10, $component->viewData('experts'));
        $this->assertSame(26, $component->viewData('experts')->total());

        $component->call('nextPage')->call('nextPage');
        $this->assertCount(6, $component->viewData('experts'));

        $component->set('search', 'Zora');
        $this->assertSame(['Zora Unique'], $component->viewData('experts')->pluck('name')->all());
        $this->assertSame(1, $component->get('paginators')['page']);
    }

    public function test_the_contributor_picker_pages_and_keeps_seated_experts_listed(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $project = Project::factory()->create(['user_id' => $user->id]);
        $experts = collect(range(1, 25))->map(fn (int $i) => Expert::factory()->create(['name' => sprintf('Persona %02d', $i)]));
        $seated = $experts->last();
        $project->addContributingExpert($seated);

        $component = Livewire::test(SelectContributors::class, ['project' => $project]);
        $this->assertCount(10, $component->viewData('experts'));
        $this->assertNotContains($seated->id, $component->viewData('experts')->pluck('id'));
        $this->assertSame([$seated->id], $component->viewData('seated')->pluck('id')->all());

        $component->set('search', 'Persona 03');
        $this->assertSame(['Persona 03'], $component->viewData('experts')->pluck('name')->all());
        $this->assertSame([$seated->id], $component->viewData('seated')->pluck('id')->all());
        $component->assertSee('Persona 25');

        $component->set('search', '')->call('nextPage')->call('nextPage');
        $this->assertCount(4, $component->viewData('experts'));
    }
}
