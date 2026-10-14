<?php

namespace Tests\Feature\Experts;

use App\Livewire\Experts\ExpertList;
use App\Models\Expert;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\TagValue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ExpertListTagFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        $this->actingAs(User::factory()->create());
    }

    private function category(string $name): TagCategory
    {
        return TagCategory::create(['scope' => TagScope::Persona, 'name' => $name]);
    }

    private function expert(string $name, array $tags = []): Expert
    {
        $expert = Expert::factory()->create(['name' => $name]);

        foreach ($tags as [$category, $value]) {
            $expert->setTag($category, $value);
        }

        return $expert;
    }

    /** @return array{0: TagCategory, 1: TagValue, 2: TagValue} */
    private function categoryWithTwoValues(string $name, string $a, string $b): array
    {
        $category = $this->category($name);

        return [$category, $category->values()->create(['name' => $a]), $category->values()->create(['name' => $b])];
    }

    public function test_cut_needs_every_picked_category_and_any_value_within_one(): void
    {
        [$gender, $female, $male] = $this->categoryWithTwoValues('gender', 'female', 'male');
        [$focus, $yes] = $this->categoryWithTwoValues('focus persona', 'true', 'false');

        $this->expert('Alice', [[$gender, $female], [$focus, $yes]]);
        $this->expert('Bella', [[$gender, $female]]);
        $this->expert('Carl', [[$gender, $male], [$focus, $yes]]);

        Livewire::test(ExpertList::class)
            ->set('tagFilter', [(string) $female->id, (string) $yes->id])
            ->assertSee('Alice')
            ->assertDontSee('Bella')
            ->assertDontSee('Carl')
            ->set('tagFilter', [(string) $female->id, (string) $male->id])
            ->assertSee('Alice')
            ->assertSee('Bella')
            ->assertSee('Carl');
    }

    public function test_merge_takes_any_picked_value(): void
    {
        [$gender, $female] = $this->categoryWithTwoValues('gender', 'female', 'male');
        [$focus, $yes] = $this->categoryWithTwoValues('focus persona', 'true', 'false');

        $this->expert('Alice', [[$gender, $female]]);
        $this->expert('Bella', [[$focus, $yes]]);
        $this->expert('Carl');

        Livewire::test(ExpertList::class)
            ->set('tagFilterMode', ExpertList::FILTER_MERGE)
            ->set('tagFilter', [(string) $female->id, (string) $yes->id])
            ->assertSee('Alice')
            ->assertSee('Bella')
            ->assertDontSee('Carl');
    }

    public function test_the_tag_filter_combines_with_the_search(): void
    {
        [$gender, $female] = $this->categoryWithTwoValues('gender', 'female', 'male');

        $this->expert('Alice', [[$gender, $female]]);
        $this->expert('Anna', [[$gender, $female]]);
        $this->expert('Alfred');

        Livewire::test(ExpertList::class)
            ->set('tagFilter', [(string) $female->id])
            ->set('search', 'Alice')
            ->assertSee('Alice')
            ->assertDontSee('Anna')
            ->assertDontSee('Alfred');
    }

    public function test_a_deleted_value_is_dropped_and_filters_nothing(): void
    {
        [$gender, $female] = $this->categoryWithTwoValues('gender', 'female', 'male');
        $this->expert('Alice', [[$gender, $female]]);
        $this->expert('Carl');

        Livewire::test(ExpertList::class)
            ->set('tagFilter', ['999999'])
            ->assertSet('tagFilter', [])
            ->assertSee('Alice')
            ->assertSee('Carl');
    }

    public function test_clearing_empties_the_values_and_keeps_the_mode(): void
    {
        [, $female] = $this->categoryWithTwoValues('gender', 'female', 'male');

        Livewire::test(ExpertList::class)
            ->set('tagFilterMode', ExpertList::FILTER_MERGE)
            ->set('tagFilter', [(string) $female->id])
            ->call('clearTagFilter')
            ->assertSet('tagFilter', [])
            ->assertSet('tagFilterMode', ExpertList::FILTER_MERGE);
    }

    public function test_without_persona_categories_there_is_no_funnel(): void
    {
        $this->expert('Alice');

        Livewire::test(ExpertList::class)
            ->assertDontSeeHtml('expert-tag-filter-dropdown');
    }

    public function test_with_persona_categories_the_funnel_shows_the_active_count(): void
    {
        [, $female] = $this->categoryWithTwoValues('gender', 'female', 'male');

        Livewire::test(ExpertList::class)
            ->assertSeeHtml('expert-tag-filter-dropdown')
            ->assertSee('Tags')
            ->set('tagFilter', [(string) $female->id])
            ->assertSee('Tags (1)');
    }

    public function test_an_active_filter_without_a_match_says_so(): void
    {
        [$gender, $female, $male] = $this->categoryWithTwoValues('gender', 'female', 'male');
        $this->expert('Alice', [[$gender, $female]]);

        Livewire::test(ExpertList::class)
            ->set('tagFilter', [(string) $male->id])
            ->assertSee(__('experts.list.no_matches'));
    }
}
