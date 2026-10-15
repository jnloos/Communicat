<?php

namespace Tests\Feature\Experts;

use App\Livewire\Experts\ExpertEditor;
use App\Models\Expert;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ExpertAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_non_admin_reads_an_expert_without_any_way_to_change_it(): void
    {
        $this->actingAs(User::factory()->create());
        $expert = Expert::factory()->create(['name' => 'Ada']);

        Livewire::test(ExpertEditor::class)
            ->call('edit', $expert->id)
            ->assertOk()
            ->assertSet('name', 'Ada')
            ->assertSee(__('experts.editor.read_only'))
            ->assertDontSee(__('experts.editor.update'))
            ->assertDontSee(__('experts.editor.delete'));
    }

    public function test_a_non_admin_can_neither_create_change_nor_delete_an_expert(): void
    {
        $this->actingAs(User::factory()->create());
        $expert = Expert::factory()->create(['name' => 'Ada', 'role' => 'Rolle']);

        Livewire::test(ExpertEditor::class)->call('edit')->assertForbidden();
        Livewire::test(ExpertEditor::class)->set('name', 'Neu')->set('role', 'Rolle')->call('save')->assertForbidden();

        Livewire::test(ExpertEditor::class)
            ->call('edit', $expert->id)
            ->set('name', 'Gekapert')
            ->call('save')
            ->assertForbidden();

        Livewire::test(ExpertEditor::class)
            ->call('edit', $expert->id)
            ->call('needsConfirmation', 'delete')
            ->call('executeConfirmed')
            ->assertForbidden();

        $this->assertSame(1, Expert::count());
        $this->assertSame('Ada', $expert->fresh()->name);
    }

    public function test_an_admin_creates_changes_and_deletes_an_expert(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        Livewire::test(ExpertEditor::class)
            ->call('edit')
            ->set('name', 'Neu')
            ->set('role', 'Rolle')
            ->call('save')
            ->assertHasNoErrors();
        $expert = Expert::where('name', 'Neu')->sole();

        Livewire::test(ExpertEditor::class)
            ->call('edit', $expert->id)
            ->assertSee(__('experts.editor.delete'))
            ->set('name', 'Umbenannt')
            ->call('save');
        $this->assertSame('Umbenannt', $expert->fresh()->name);

        Livewire::test(ExpertEditor::class)
            ->call('edit', $expert->id)
            ->call('needsConfirmation', 'delete')
            ->call('executeConfirmed');
        $this->assertNull($expert->fresh());
    }

    public function test_the_create_button_is_shown_to_admins_only(): void
    {
        $label = __('experts.list.create');

        $this->actingAs(User::factory()->create())->get(route('experts'))->assertOk()->assertDontSee($label);
        $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('experts'))->assertOk()->assertSee($label);
    }
}
