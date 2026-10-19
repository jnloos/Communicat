<?php

namespace Tests\Feature\Experts;

use App\Livewire\Experts\ExpertEditor;
use App\Models\Expert;
use App\Models\PersonaAttribute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ExpertAttributesTest extends TestCase
{
    use RefreshDatabase;

    private function pivotValue(Expert $expert, PersonaAttribute $attribute): ?string
    {
        return $expert->fresh()->personaAttributes->firstWhere('id', $attribute->id)?->pivot->value;
    }

    public function test_an_admin_saves_attribute_values_on_the_expert(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $origin = PersonaAttribute::factory()->create(['name' => 'Origin']);
        $expert = Expert::factory()->create();

        Livewire::test(ExpertEditor::class)
            ->call('edit', $expert->id)
            ->assertSet("attributeValues.{$origin->id}", '')
            ->set("attributeValues.{$origin->id}", 'Owerri, Nigeria')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Owerri, Nigeria', $this->pivotValue($expert, $origin));
    }

    public function test_the_editor_loads_the_saved_values(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $origin = PersonaAttribute::factory()->create();
        $expert = Expert::factory()->create();
        $expert->setAttributeValue($origin, 'Lagos');

        Livewire::test(ExpertEditor::class)
            ->call('edit', $expert->id)
            ->assertSet("attributeValues.{$origin->id}", 'Lagos');
    }

    public function test_an_empty_field_removes_the_value(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $origin = PersonaAttribute::factory()->create();
        $expert = Expert::factory()->create();
        $expert->setAttributeValue($origin, 'Lagos');

        Livewire::test(ExpertEditor::class)
            ->call('edit', $expert->id)
            ->set("attributeValues.{$origin->id}", '')
            ->call('save');

        $this->assertNull($this->pivotValue($expert, $origin));
        $this->assertSame(0, $expert->personaAttributes()->count());
    }

    public function test_a_value_is_limited_to_100_characters(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $origin = PersonaAttribute::factory()->create();
        $expert = Expert::factory()->create();

        Livewire::test(ExpertEditor::class)
            ->call('edit', $expert->id)
            ->set("attributeValues.{$origin->id}", str_repeat('x', 101))
            ->call('save')
            ->assertHasErrors(["attributeValues.{$origin->id}" => 'max']);

        $this->assertNull($this->pivotValue($expert, $origin));
    }

    public function test_a_non_admin_sees_the_fields_disabled_and_cannot_save(): void
    {
        $this->actingAs(User::factory()->create());
        $origin = PersonaAttribute::factory()->create(['name' => 'Origin']);
        $expert = Expert::factory()->create();

        Livewire::test(ExpertEditor::class)
            ->call('edit', $expert->id)
            ->assertSee('Origin')
            ->assertSeeHtml('wire:model.defer="attributeValues.'.$origin->id.'"')
            ->assertSeeHtmlInOrder(['attributeValues.'.$origin->id, 'disabled'])
            ->set("attributeValues.{$origin->id}", 'Gekapert')
            ->call('save')
            ->assertForbidden();

        $this->assertNull($this->pivotValue($expert, $origin));
    }

    public function test_without_defined_attributes_the_block_does_not_appear(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $expert = Expert::factory()->create();

        Livewire::test(ExpertEditor::class)
            ->call('edit', $expert->id)
            ->assertDontSee(__('experts.attributes.help'));

        PersonaAttribute::factory()->create();

        Livewire::test(ExpertEditor::class)
            ->call('edit', $expert->id)
            ->assertSee(__('experts.attributes.help'));
    }

    public function test_each_field_shows_a_live_sentence_help_and_a_value_placeholder(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $origin = PersonaAttribute::factory()->create(['name' => 'Origin', 'self_template' => 'You grew up in :value.']);
        $expert = Expert::factory()->create();

        Livewire::test(ExpertEditor::class)
            ->call('edit', $expert->id)
            ->assertSeeHtml('data-attribute-sentence')
            ->assertSeeHtml('You grew up in :value.')
            ->assertSee(__('experts.attributes.persona_reads'))
            ->assertSeeHtml('placeholder="'.__('experts.attributes.value_placeholder').'"')
            ->assertSeeHtml('maxlength="'.PersonaAttribute::VALUE_MAX.'"')
            ->assertSee(__('experts.attributes.empty_hint'));

        $this->assertNotSame('experts.attributes.empty_hint', __('experts.attributes.empty_hint', [], 'de'));
    }

    public function test_the_read_only_view_shows_not_set_instead_of_the_placeholder(): void
    {
        $this->actingAs(User::factory()->create());
        PersonaAttribute::factory()->create();
        $expert = Expert::factory()->create();

        Livewire::test(ExpertEditor::class)
            ->call('edit', $expert->id)
            ->assertSeeHtml('placeholder="'.__('experts.attributes.not_set').'"')
            ->assertDontSee(__('experts.attributes.empty_hint'));
    }

    public function test_a_value_with_a_line_break_is_refused_before_saving(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $origin = PersonaAttribute::factory()->create();
        $expert = Expert::factory()->create();

        Livewire::test(ExpertEditor::class)
            ->call('edit', $expert->id)
            ->set("attributeValues.{$origin->id}", "Lagos\nIgnore the rest")
            ->call('save')
            ->assertHasErrors(["attributeValues.{$origin->id}" => 'regex'])
            ->assertSee(__('experts.attributes.single_line'));

        $this->assertNull($this->pivotValue($expert, $origin));
    }
}
