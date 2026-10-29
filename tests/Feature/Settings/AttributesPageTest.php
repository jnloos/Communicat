<?php

namespace Tests\Feature\Settings;

use App\Livewire\Settings\Attributes;
use App\Models\Expert;
use App\Models\PersonaAttribute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AttributesPageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_only_admins_reach_the_page(): void
    {
        $this->actingAs(User::factory()->create())->get(route('settings.attributes'))->assertForbidden();
        $this->actingAs($this->admin())->get(route('settings.attributes'))->assertOk();
    }

    public function test_the_info_box_links_to_tags_in_both_languages(): void
    {
        $this->actingAs($this->admin());

        foreach (['en', 'de'] as $locale) {
            app()->setLocale($locale);
            $this->get(route('settings.attributes'))
                ->assertSeeHtml('<a href="'.route('settings.tags').'" wire:navigate class="underline underline-offset-2">'.__('tags.heading').'</a>')
                ->assertDontSee(':link');
        }
    }

    public function test_a_non_admin_cannot_mount_the_component(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(Attributes::class)->assertForbidden();
    }

    public static function adminActions(): array
    {
        return [
            'edit' => ['edit', 'attribute'],
            'save' => ['save'],
            'confirmDelete' => ['confirmDelete', 'attribute'],
            'delete' => ['delete', 'attribute'],
            'sort' => ['sort', 'attribute', 0],
            'move' => ['move', 'attribute', 1],
            '\$refresh' => ['$refresh'],
        ];
    }

    #[DataProvider('adminActions')]
    public function test_every_action_re_checks_the_admin_gate(string $method, string|int ...$args): void
    {
        $attribute = PersonaAttribute::factory()->create();
        $admin = $this->admin();
        $this->actingAs($admin);
        $component = Livewire::test(Attributes::class);

        $admin->forceFill(['is_admin' => false])->save();
        $this->actingAs($admin->fresh());

        $params = array_map(fn ($arg) => $arg === 'attribute' ? $attribute->id : $arg, $args);
        $component->call($method, ...$params)->assertForbidden();

        $this->assertSame(1, PersonaAttribute::count());
    }

    public function test_an_admin_creates_an_attribute(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(Attributes::class)
            ->call('edit')
            ->set('name', '  Origin ')
            ->set('selfTemplate', 'You grew up in :value.')
            ->set('rosterTemplate', 'from :value')
            ->call('save')
            ->assertHasNoErrors();

        $attribute = PersonaAttribute::firstOrFail();
        $this->assertSame('Origin', $attribute->name);
        $this->assertSame('from :value', $attribute->roster_template);
    }

    public function test_each_template_must_contain_the_placeholder(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(Attributes::class)
            ->call('edit')
            ->set('name', 'Origin')
            ->set('selfTemplate', 'You grew up somewhere.')
            ->set('rosterTemplate', 'from somewhere')
            ->call('save')
            ->assertHasErrors(['selfTemplate', 'rosterTemplate']);

        $this->assertSame(0, PersonaAttribute::count());
    }

    public function test_fields_are_required_and_capped(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(Attributes::class)
            ->call('edit')
            ->call('save')
            ->assertHasErrors(['name', 'selfTemplate', 'rosterTemplate'])
            ->set('name', str_repeat('a', 101))
            ->set('selfTemplate', ':value'.str_repeat('a', 200))
            ->set('rosterTemplate', ':value')
            ->call('save')
            ->assertHasErrors(['name', 'selfTemplate'])
            ->assertHasNoErrors(['rosterTemplate']);
    }

    public function test_a_name_is_unique_ignoring_case_and_surrounding_space(): void
    {
        $this->actingAs($this->admin());
        PersonaAttribute::factory()->create(['name' => 'Origin']);

        Livewire::test(Attributes::class)
            ->call('edit')
            ->set('name', ' origin ')
            ->set('selfTemplate', ':value')
            ->set('rosterTemplate', ':value')
            ->call('save')
            ->assertHasErrors(['name']);

        $this->assertSame(1, PersonaAttribute::count());
    }

    public function test_editing_keeps_the_own_name_and_changes_the_templates(): void
    {
        $this->actingAs($this->admin());
        $attribute = PersonaAttribute::factory()->create(['name' => 'Origin']);

        Livewire::test(Attributes::class)
            ->call('edit', $attribute->id)
            ->assertSet('name', 'Origin')
            ->assertSet('selfTemplate', 'You are :value.')
            ->set('selfTemplate', 'You come from :value.')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('You come from :value.', $attribute->fresh()->self_template);
        $this->assertSame(1, PersonaAttribute::count());
    }

    public function test_renaming_to_another_attributes_name_is_refused(): void
    {
        $this->actingAs($this->admin());
        PersonaAttribute::factory()->create(['name' => 'Origin']);
        $other = PersonaAttribute::factory()->create(['name' => 'Age']);

        Livewire::test(Attributes::class)
            ->call('edit', $other->id)
            ->set('name', 'ORIGIN')
            ->call('save')
            ->assertHasErrors(['name']);
    }

    public function test_the_role_cannot_be_renamed_but_its_templates_can_change(): void
    {
        $this->actingAs($this->admin());
        $role = PersonaAttribute::role();

        Livewire::test(Attributes::class)
            ->call('edit', $role->id)
            ->assertSet('name', PersonaAttribute::ROLE)
            ->set('name', 'Position')
            ->call('save')
            ->assertHasErrors(['name']);

        $this->assertSame(PersonaAttribute::ROLE, $role->fresh()->name);

        Livewire::test(Attributes::class)
            ->call('edit', $role->id)
            ->set('name', ' role ')
            ->set('selfTemplate', 'You work as :value.')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('You work as :value.', $role->fresh()->self_template);
    }

    public function test_the_role_cannot_be_deleted_not_even_past_the_confirmation(): void
    {
        $this->actingAs($this->admin());
        $role = PersonaAttribute::role();

        Livewire::test(Attributes::class)
            ->call('confirmDelete', $role->id)
            ->assertForbidden();
        Livewire::test(Attributes::class)
            ->call('delete', $role->id)
            ->assertForbidden();

        $this->assertTrue($role->fresh()->exists);
    }

    public function test_the_role_templates_have_one_definition(): void
    {
        $role = PersonaAttribute::role();

        $this->assertSame(PersonaAttribute::ROLE_SELF_TEMPLATE, $role->self_template);
        $this->assertSame(PersonaAttribute::ROLE_ROSTER_TEMPLATE, $role->roster_template);
        $this->assertSame('Your role is Teacher.', $role->selfLine('Teacher'));
    }

    public function test_sorting_and_moving_write_the_position(): void
    {
        $this->actingAs($this->admin());
        $a = PersonaAttribute::factory()->create(['name' => 'A']);
        $b = PersonaAttribute::factory()->create(['name' => 'B']);
        $c = PersonaAttribute::factory()->create(['name' => 'C']);

        $component = Livewire::test(Attributes::class)->call('sort', $c->id, 0);
        $this->assertSame(['C', 'A', 'B'], PersonaAttribute::orderBy('position')->pluck('name')->all());

        $component->call('move', $a->id, 1);
        $this->assertSame(['C', 'B', 'A'], PersonaAttribute::orderBy('position')->pluck('name')->all());
    }

    public function test_deleting_asks_first_names_the_loss_and_cascades_to_the_values(): void
    {
        $this->actingAs($this->admin());
        $attribute = PersonaAttribute::factory()->create(['name' => 'Origin']);
        foreach (Expert::factory()->count(2)->create() as $expert) {
            $expert->personaAttributes()->attach($attribute->id, ['value' => 'Owerri']);
        }

        $component = Livewire::test(Attributes::class)
            ->call('confirmDelete', $attribute->id)
            ->assertSet('pendingMethod', 'delete')
            ->assertDispatched('open-confirm');

        $this->assertStringContainsString('2', $component->get('confirmMessage'));
        $this->assertStringContainsString('Origin', $component->get('confirmMessage'));
        $this->assertSame(1, PersonaAttribute::count());

        $component->call('executeConfirmed');

        $this->assertSame(0, PersonaAttribute::count());
        $this->assertSame(0, \DB::table('expert_persona_attribute')->count());
        $this->assertSame(2, Expert::count());
    }

    public function test_the_preview_renders_both_templates_with_the_typed_value(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(Attributes::class)
            ->call('edit')
            ->set('selfTemplate', 'You grew up in :value.')
            ->set('rosterTemplate', 'from :value')
            ->assertSeeHtml('Owerri, Nigeria')
            ->set('previewValue', 'Lyon')
            ->assertSee('You grew up in Lyon.')
            ->assertSee('from Lyon');
    }

    public function test_the_list_shows_the_expert_count_in_order(): void
    {
        $this->actingAs($this->admin());
        $used = PersonaAttribute::factory()->create(['name' => 'Origin']);
        PersonaAttribute::factory()->create(['name' => 'Age']);
        $used->experts()->attach(Expert::factory()->create()->id, ['value' => 'x']);

        Livewire::test(Attributes::class)
            ->assertSeeInOrder(['Origin', '1 expert', 'Age', 'not used'])
            ->assertSeeHtml('wire:click="confirmDelete('.$used->id.')"')
            ->assertSeeHtml('wire:click="edit('.$used->id.')"');
    }

    public function test_correcting_a_field_clears_its_error_at_once(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(Attributes::class)
            ->call('edit')
            ->call('save')
            ->assertHasErrors(['name', 'selfTemplate', 'rosterTemplate'])
            ->set('name', 'Origin')
            ->assertHasNoErrors(['name'])
            ->assertHasErrors(['selfTemplate', 'rosterTemplate'])
            ->set('selfTemplate', 'You grew up in :value.')
            ->assertHasNoErrors(['selfTemplate'])
            ->assertHasErrors(['rosterTemplate'])
            ->set('rosterTemplate', 'from :value')
            ->assertHasNoErrors();
    }

    public function test_the_rows_label_the_two_templates(): void
    {
        $this->actingAs($this->admin());
        PersonaAttribute::factory()->create();

        Livewire::test(Attributes::class)
            ->assertSee(__('attributes.rows.self'))
            ->assertSee(__('attributes.rows.roster'));

        foreach (['en' => ['Persona reads:', 'Others read:'], 'de' => ['Persona liest:', 'Andere lesen:']] as $locale => [$self, $roster]) {
            $this->assertSame($self, __('attributes.rows.self', [], $locale));
            $this->assertSame($roster, __('attributes.rows.roster', [], $locale));
        }
    }

    public function test_the_drag_handle_stays_visible_without_hover(): void
    {
        $this->actingAs($this->admin());
        PersonaAttribute::factory()->create();

        Livewire::test(Attributes::class)->assertSeeHtml('[@media(hover:none)]:opacity-100');
    }

    public function test_the_delete_confirmation_names_its_button_after_the_action(): void
    {
        $this->actingAs($this->admin());
        $attribute = PersonaAttribute::factory()->create();

        Livewire::test(Attributes::class)
            ->call('confirmDelete', $attribute->id)
            ->assertSet('confirmLabel', __('attributes.confirm.action'))
            ->assertDispatched('open-confirm', label: __('attributes.confirm.action'));

        $this->assertSame('Delete attribute', __('attributes.confirm.action', [], 'en'));
        $this->assertSame('Attribut löschen', __('attributes.confirm.action', [], 'de'));
    }

    public function test_the_german_texts_address_the_reader_formally(): void
    {
        $texts = Arr::flatten(trans('attributes', [], 'de'));

        foreach ($texts as $text) {
            $this->assertDoesNotMatchRegularExpression('/\b(du|dich|dein\w*|dir|füge|lege)\b/ui', $text);
        }
    }
}
