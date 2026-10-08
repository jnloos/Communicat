<?php

namespace Tests\Feature\Statistics;

use App\Livewire\Statistics\StatisticsPage;
use App\Models\Comparison;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StatisticsPageTest extends TestCase
{
    use RefreshDatabase;

    private function comparisonOf(User $user, string $name = 'Status-Effekt'): Comparison
    {
        return $user->comparisons()->create(['name' => $name, 'series' => [], 'view' => []]);
    }

    public function test_guests_are_sent_to_the_login(): void
    {
        $this->get(route('statistics'))->assertRedirect(route('login'));
    }

    public function test_every_signed_in_user_reaches_the_page_and_the_sidebar_links_it(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('statistics'))
            ->assertOk()
            ->assertSee(__('comparisons.heading'))
            ->assertSee(route('statistics'));
    }

    public function test_creating_names_and_opens_a_new_comparison(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(StatisticsPage::class)
            ->call('startCreating')
            ->set('name', '  Titelträger ')
            ->call('saveName')
            ->assertHasNoErrors()
            ->assertSet('comparisonId', Comparison::sole()->id);

        $this->assertSame('Titelträger', Comparison::sole()->name);
        $this->assertSame($user->id, Comparison::sole()->user_id);
    }

    public function test_renaming_and_duplicating(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $source = $user->comparisons()->create(['name' => 'A', 'series' => [['label' => 'Reihe 1']], 'view' => ['metric' => 'word_share']]);

        Livewire::test(StatisticsPage::class)
            ->call('startRenaming', $source->id)
            ->assertSet('name', 'A')
            ->set('name', 'B')
            ->call('saveName')
            ->call('duplicate', $source->id);

        $this->assertSame('B', $source->fresh()->name);
        $copy = Comparison::whereKeyNot($source->id)->sole();
        $this->assertSame(__('comparisons.copy_name', ['name' => 'B']), $copy->name);
        $this->assertSame($source->fresh()->series, $copy->series);
        $this->assertSame($source->fresh()->view, $copy->view);
    }

    public function test_deleting_asks_first(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $comparison = $this->comparisonOf($user);

        Livewire::test(StatisticsPage::class, ['comparisonId' => $comparison->id])
            ->call('confirmDelete', $comparison->id)
            ->assertDispatched('open-confirm')
            ->call('executeConfirmed')
            ->assertSet('comparisonId', null);

        $this->assertSame(0, Comparison::count());
    }

    public function test_someone_elses_comparison_does_not_exist_for_you(): void
    {
        $stranger = User::factory()->create();
        $foreign = $this->comparisonOf($stranger);
        $this->actingAs(User::factory()->create());

        // findOrFail: the framework renders ModelNotFoundException as a 404 over HTTP; Livewire's test helper rethrows it.
        foreach (['open', 'startRenaming', 'duplicate', 'confirmDelete', 'delete'] as $action) {
            $this->assertThrows(
                fn () => Livewire::test(StatisticsPage::class)->call($action, $foreign->id),
                ModelNotFoundException::class,
            );
        }
        $this->assertSame(1, Comparison::count());
        Livewire::withQueryParams(['c' => $foreign->id])->test(StatisticsPage::class)->assertSet('comparisonId', null);
    }

    public function test_a_users_comparisons_go_with_the_user(): void
    {
        $user = User::factory()->create();
        $this->comparisonOf($user);

        $user->delete();

        $this->assertSame(0, Comparison::count());
    }

    public function test_new_comparison_is_the_primary_action_only_while_the_list_is_empty(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $primary = '/<button[^>]*bg-\[var\(--color-accent\)\][^>]*wire:click="startCreating"/';
        $this->assertMatchesRegularExpression($primary, Livewire::test(StatisticsPage::class)->html());

        $this->comparisonOf($user);
        $this->assertDoesNotMatchRegularExpression($primary, Livewire::test(StatisticsPage::class)->html());
    }
}
