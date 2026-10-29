<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Experts\ExpertEditor;
use App\Livewire\Projects\CreateProject;
use App\Livewire\Projects\EditProject;
use App\Livewire\Projects\SelectContributors;
use App\Models\Expert;
use App\Models\PersonaAttribute;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FlyoutGuardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_edit_flyouts_route_every_way_out_through_the_unsaved_guard(): void
    {
        $owner = User::factory()->create(['is_admin' => true]);
        $this->actingAs($owner);
        $project = Project::factory()->create(['user_id' => $owner->id]);

        $project = Livewire::test(EditProject::class, ['project' => $project]);
        $expert = Livewire::test(ExpertEditor::class)->call('edit');

        foreach ([[$project, 'edit-project'], [$expert, 'edit-expert']] as [$component, $name]) {
            $component
                // Escape and a click outside arrive as the dialog's cancel event.
                ->assertSeeHtml('x-on:cancel="$store.unsaved.request(&quot;'.$name.'&quot;, $event)"')
                // The X and Cancel ask too; Flux's own X closes without any event.
                ->assertSeeHtml("x-on:click=\"\$store.unsaved.request('{$name}')\"")
                ->assertSeeHtml('x-on:click="$store.unsaved.request(&quot;'.$name.'&quot;)"')
                ->assertSeeHtml('x-on:modal-show.document');
        }
    }

    public function test_the_shared_discard_dialog_keeps_editing_in_focus(): void
    {
        $this->actingAs(User::factory()->create());

        $html = view('components.discard-modal')->render();

        $this->assertStringContainsString('Discard changes?', $html);
        $this->assertMatchesRegularExpression('/autofocus[^>]*>\s*Keep editing/', $html);
        $this->assertStringContainsString('$store.unsaved.discard()', $html);
        $this->assertStringContainsString('Discard', $html);
    }

    public function test_the_flyout_opens_on_what_is_saved_not_on_dropped_edits(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $project = Project::factory()->create(['user_id' => $owner->id, 'title' => 'Gespeichert']);

        Livewire::test(EditProject::class, ['project' => $project])
            ->set('form.title', 'Verworfen')
            ->call('select')
            ->assertSet('form.title', 'Gespeichert');

        $this->assertSame('Gespeichert', $project->fresh()->title);
    }

    public function test_the_attributes_header_shows_the_values_and_not_set_in_grey(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $role = PersonaAttribute::factory()->create(['name' => 'Role']);
        $gender = PersonaAttribute::factory()->create(['name' => 'Gender']);
        $expert = Expert::factory()->create();
        $expert->setAttributeValue($role, 'Teacher');
        $expert->setAttributeValue($gender, 'Woman');

        // Live in the browser from $wire.attributeValues: the markup carries the ids in order and the empty text.
        Livewire::test(ExpertEditor::class)
            ->call('edit', $expert->id)
            ->assertSeeHtml('data-attribute-summary')
            ->assertSeeHtml('['.$role->id.','.$gender->id.']')
            ->assertSeeHtml('$wire.attributeValues?.[id]')
            ->assertSee('Not set')
            ->assertSet("attributeValues.{$role->id}", 'Teacher')
            ->assertSet("attributeValues.{$gender->id}", 'Woman');
    }

    public function test_every_opening_gets_a_fresh_accordion_so_both_sections_start_closed(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        PersonaAttribute::factory()->create();
        $first = Expert::factory()->create();
        $second = Expert::factory()->create();

        $component = Livewire::test(ExpertEditor::class)->call('edit', $first->id)->assertSet('openings', 1);
        $component->call('edit', $second->id)->assertSet('openings', 2)->assertSeeHtml('sections-2');
        $component->call('edit')->assertSet('openings', 3);
    }

    public function test_the_name_is_marked_as_required(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $html = Livewire::test(ExpertEditor::class)->call('edit')->html();

        // The asterisk is the label's ::after; the input itself carries the real attribute.
        $this->assertMatchesRegularExpression('/<input[^>]*name="name"[^>]*required="required"|<input[^>]*required="required"[^>]*name="name"/s', $html);
        $this->assertStringContainsString("after:content-['*']", $html);
    }

    public function test_creating_a_project_says_create_project_in_both_languages(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateProject::class)->assertSee('Create project')->assertDontSee('Start Discussion');

        app()->setLocale('de');
        Livewire::test(CreateProject::class)->assertSee('Projekt anlegen')->assertDontSee('Diskussion starten');
    }

    public function test_the_participants_flyout_ends_in_a_single_done_button(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $project = Project::factory()->create(['user_id' => $owner->id]);

        Livewire::test(SelectContributors::class, ['project' => $project])->assertSee('Done');
    }
}
