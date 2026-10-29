<?php

namespace Tests\Feature\Settings;

use App\Livewire\Settings\Tags;
use App\Models\Expert;
use App\Models\Project;
use App\Models\TagCategory;
use App\Models\Taggable;
use App\Models\TagScope;
use App\Models\TagValue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_the_info_box_links_to_attributes_in_both_languages(): void
    {
        $this->actingAs($this->admin());

        foreach (['en', 'de'] as $locale) {
            app()->setLocale($locale);
            $this->get(route('settings.tags'))
                ->assertSeeHtml('<a href="'.route('settings.attributes').'" wire:navigate class="underline underline-offset-2">'.__('attributes.heading').'</a>')
                ->assertSee('Title-holder: true')
                ->assertDontSee(':link');
        }
    }

    public function test_a_non_admin_cannot_mount_the_component(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(Tags::class)->assertForbidden();
    }

    /** Every request after the mount, as an admin who loses the flag in between would send it. */
    public static function adminActions(): array
    {
        return [
            'createCategory' => ['createCategory'],
            'createValue' => ['createValue', 'value'],
            'startRenamingCategory' => ['startRenamingCategory', 'category'],
            'startRenamingValue' => ['startRenamingValue', 'value'],
            'saveCategoryName' => ['saveCategoryName'],
            'saveValueName' => ['saveValueName'],
            'cancelRenaming' => ['cancelRenaming'],
            'confirmDeleteCategory' => ['confirmDeleteCategory', 'category'],
            'deleteCategory' => ['deleteCategory', 'category'],
            'confirmDeleteValue' => ['confirmDeleteValue', 'value'],
            'deleteValue' => ['deleteValue', 'value'],
            'sortCategory' => ['sortCategory', 'category', 0],
            'sortValue' => ['sortValue', 'value', 0],
            'moveCategory' => ['moveCategory', 'category', 1],
            'moveValue' => ['moveValue', 'value', 1],
            '\$refresh' => ['$refresh'],
        ];
    }

    #[DataProvider('adminActions')]
    public function test_every_action_is_rejected_once_the_user_is_no_admin(string $action, ?string $target = null, mixed ...$more): void
    {
        $admin = $this->admin();
        $category = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'Geschlecht']);
        $value = $category->values()->create(['name' => 'weiblich']);
        $this->actingAs($admin);
        $component = Livewire::test(Tags::class);

        $admin->update(['is_admin' => false]);
        $params = match ($target) {
            'category' => [$category->id, ...$more],
            'value' => [$value->id, ...$more],
            default => [],
        };

        $component->call($action, ...$params)->assertForbidden();
        $this->assertSame(1, TagCategory::count());
        $this->assertSame(1, TagValue::count());
    }

    public function test_an_admin_creates_a_category_and_a_value_in_the_chosen_scope(): void
    {
        $this->actingAs($this->admin());

        $component = Livewire::test(Tags::class)
            ->set('scope', 'project')
            ->set('newCategoryName', '  status condition ')
            ->call('createCategory')
            ->assertHasNoErrors();

        $category = TagCategory::sole();
        $this->assertSame(TagScope::Project, $category->scope);
        $this->assertSame('status condition', $category->name);

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

    public function test_a_category_name_is_unique_regardless_of_case_and_space(): void
    {
        $this->actingAs($this->admin());
        $category = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'Geschlecht']);

        Livewire::test(Tags::class)
            ->set('newCategoryName', ' geschlecht')->call('createCategory')->assertHasErrors('newCategoryName')
            ->call('startRenamingCategory', $category->id)
            ->set('renameTo', 'GESCHLECHT')->call('saveCategoryName')->assertHasNoErrors();

        $this->assertSame(['GESCHLECHT'], TagCategory::pluck('name')->all());
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

        $this->assertSame('Frau', TagValue::find($expert->fresh()->tagSelection()[$category->id] ?? null)?->name);
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

    public function test_move_up_and_down_sorts_without_dragging(): void
    {
        $this->actingAs($this->admin());
        $a = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'A']);
        TagCategory::create(['scope' => TagScope::Persona, 'name' => 'B']);
        $c = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'C']);
        $first = $a->values()->create(['name' => 'eins']);
        $a->values()->create(['name' => 'zwei']);

        Livewire::test(Tags::class)
            ->call('moveCategory', $c->id, -1)
            ->call('moveCategory', $a->id, 1)
            ->call('moveValue', $first->id, 1);

        $this->assertSame(['C', 'A', 'B'], TagCategory::ofScope(TagScope::Persona)->pluck('name')->all());
        $this->assertSame(['zwei', 'eins'], $a->values()->pluck('name')->all());
    }

    public function test_moving_past_either_end_keeps_the_order(): void
    {
        $this->actingAs($this->admin());
        $a = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'A']);
        $b = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'B']);

        Livewire::test(Tags::class)
            ->call('moveCategory', $a->id, -1)
            ->call('moveCategory', $b->id, 1);

        $this->assertSame(['A', 'B'], TagCategory::ofScope(TagScope::Persona)->pluck('name')->all());
    }

    public function test_the_row_menus_offer_move_up_and_down(): void
    {
        $this->actingAs($this->admin());
        $a = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'A']);
        TagCategory::create(['scope' => TagScope::Persona, 'name' => 'B']);
        $value = $a->values()->create(['name' => 'eins']);

        Livewire::test(Tags::class)
            ->assertSeeHtml('wire:click="moveCategory('.$a->id.', -1)"')
            ->assertSeeHtml('wire:click="moveCategory('.$a->id.', 1)"')
            ->assertSeeHtml('wire:click="moveValue('.$value->id.', 1)"')
            ->assertSee('Move up')
            ->assertSee('Move down');
    }

    public function test_each_value_counts_its_experts_or_projects_in_words(): void
    {
        $this->actingAs($this->admin());
        $category = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'Geschlecht']);
        $value = $category->values()->create(['name' => 'weiblich']);
        $category->values()->create(['name' => 'männlich']);
        Expert::factory()->create()->setTag($category, $value);
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'status condition']);
        $status = $condition->values()->create(['name' => 'status']);
        Project::factory()->count(2)->create()->each->setTag($condition, $status);

        Livewire::test(Tags::class)
            ->assertSee('1 expert')
            ->assertSee('not used')
            ->assertDontSee('assignment')
            ->set('scope', 'project')
            ->assertSee('2 projects');
    }

    public function test_the_scopes_are_called_experts_and_projects(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(Tags::class)
            ->assertSee('Experts')
            ->assertDontSee('Personas');
    }

    public function test_every_row_offers_rename_and_delete(): void
    {
        $this->actingAs($this->admin());
        $category = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'Geschlecht']);
        $value = $category->values()->create(['name' => 'weiblich']);

        Livewire::test(Tags::class)
            ->assertSeeHtml('wire:click="startRenamingCategory('.$category->id.')"')
            ->assertSeeHtml('wire:click="confirmDeleteCategory('.$category->id.')"')
            ->assertSeeHtml('wire:click="startRenamingValue('.$value->id.')"')
            ->assertSeeHtml('wire:click="confirmDeleteValue('.$value->id.')"');
    }

    public function test_a_rename_can_be_cancelled_and_the_new_name_fields_are_labelled(): void
    {
        $this->actingAs($this->admin());
        $category = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'Geschlecht']);

        Livewire::test(Tags::class)
            ->assertSeeHtml('aria-label="'.__('tags.placeholders.new_category').'"')
            ->assertSeeHtml('aria-label="'.__('tags.placeholders.new_value').'"')
            ->call('startRenamingCategory', $category->id)
            ->assertSeeHtml('wire:click="cancelRenaming"')
            ->call('cancelRenaming')
            ->assertSet('renamingCategoryId', null);
    }
}
