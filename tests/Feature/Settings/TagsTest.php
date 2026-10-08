<?php

namespace Tests\Feature\Settings;

use App\Livewire\Settings\Tags;
use App\Models\Expert;
use App\Models\TagCategory;
use App\Models\Taggable;
use App\Models\TagScope;
use App\Models\TagValue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TagsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_only_admins_reach_the_page(): void
    {
        $this->actingAs(User::factory()->create())->get(route('settings.tags'))->assertForbidden();
        $this->actingAs($this->admin())->get(route('settings.tags'))->assertOk();
    }

    public function test_a_non_admin_cannot_mount_the_component(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(Tags::class)->assertForbidden();
    }

    public function test_an_admin_creates_a_category_and_a_value_in_the_chosen_scope(): void
    {
        $this->actingAs($this->admin());

        $component = Livewire::test(Tags::class)
            ->set('scope', 'project')
            ->set('newCategoryName', '  bedingung ')
            ->call('createCategory')
            ->assertHasNoErrors();

        $category = TagCategory::sole();
        $this->assertSame(TagScope::Project, $category->scope);
        $this->assertSame('bedingung', $category->name);

        $component->set("newValueNames.{$category->id}", 'status')
            ->call('createValue', $category->id)
            ->assertHasNoErrors();

        $this->assertSame(['status'], $category->values()->pluck('name')->all());
    }

    public function test_names_are_required_short_and_unique_in_their_scope(): void
    {
        $this->actingAs($this->admin());
        TagCategory::create(['scope' => TagScope::Persona, 'name' => 'Geschlecht']);

        Livewire::test(Tags::class)
            ->set('newCategoryName', 'Geschlecht')->call('createCategory')->assertHasErrors(['newCategoryName' => 'unique'])
            ->set('newCategoryName', '')->call('createCategory')->assertHasErrors(['newCategoryName' => 'required'])
            ->set('newCategoryName', str_repeat('x', 51))->call('createCategory')->assertHasErrors(['newCategoryName' => 'max'])
            ->set('scope', 'project')->set('newCategoryName', 'Geschlecht')->call('createCategory')->assertHasNoErrors();

        $this->assertSame(2, TagCategory::count());
    }

    public function test_renaming_inline_keeps_the_assignments(): void
    {
        $this->actingAs($this->admin());
        $category = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'Geschlecht']);
        $value = $category->values()->create(['name' => 'weiblich']);
        $expert = Expert::factory()->create();
        $expert->setTag($category, $value);

        Livewire::test(Tags::class)
            ->call('startRenamingValue', $value->id)
            ->assertSet('renameTo', 'weiblich')
            ->set('renameTo', 'Frau')
            ->call('saveValueName')
            ->assertHasNoErrors()
            ->assertSet('renamingValueId', null)
            ->call('startRenamingCategory', $category->id)
            ->set('renameTo', 'Gender')
            ->call('saveCategoryName');

        $this->assertSame('Frau', $expert->fresh()->tagFor($category->fresh())?->name);
        $this->assertSame('Gender', $category->fresh()->name);
    }

    public function test_deleting_a_value_asks_first_and_names_the_assignments(): void
    {
        $this->actingAs($this->admin());
        $category = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'Geschlecht']);
        $value = $category->values()->create(['name' => 'weiblich']);
        foreach (Expert::factory()->count(7)->create() as $expert) {
            $expert->setTag($category, $value);
        }

        $component = Livewire::test(Tags::class)
            ->call('confirmDeleteValue', $value->id)
            ->assertSet('pendingMethod', 'deleteValue')
            ->assertDispatched('open-confirm');

        $this->assertStringContainsString('7', $component->get('confirmMessage'));
        $this->assertStringContainsString('weiblich', $component->get('confirmMessage'));
        $this->assertSame(1, TagValue::count());

        $component->call('executeConfirmed');

        $this->assertSame(0, TagValue::count());
        $this->assertSame(0, Taggable::count());
    }

    public function test_deleting_a_category_names_its_values_and_takes_them_along(): void
    {
        $this->actingAs($this->admin());
        $category = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'Geschlecht']);
        $category->values()->create(['name' => 'weiblich']);
        $category->values()->create(['name' => 'männlich']);

        $component = Livewire::test(Tags::class)->call('confirmDeleteCategory', $category->id);

        $this->assertStringContainsString('2', $component->get('confirmMessage'));

        $component->call('executeConfirmed');

        $this->assertSame(0, TagCategory::count());
        $this->assertSame(0, TagValue::count());
    }

    public function test_sorting_writes_the_position(): void
    {
        $this->actingAs($this->admin());
        $a = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'A']);
        TagCategory::create(['scope' => TagScope::Persona, 'name' => 'B']);
        $c = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'C']);
        $first = $a->values()->create(['name' => 'eins']);
        $second = $a->values()->create(['name' => 'zwei']);

        Livewire::test(Tags::class)
            ->call('sortCategory', $c->id, 0)
            ->call('sortValue', $second->id, 0);

        $this->assertSame(['C', 'A', 'B'], TagCategory::ofScope(TagScope::Persona)->pluck('name')->all());
        $this->assertSame(['zwei', 'eins'], $a->values()->pluck('name')->all());
        $this->assertSame(2, $first->fresh()->position);
    }

    public function test_each_value_shows_its_assignment_count(): void
    {
        $this->actingAs($this->admin());
        $category = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'Geschlecht']);
        $value = $category->values()->create(['name' => 'weiblich']);
        Expert::factory()->create()->setTag($category, $value);

        Livewire::test(Tags::class)->assertSee(trans_choice('tags.assignments', 1, ['count' => 1]));
    }
}
