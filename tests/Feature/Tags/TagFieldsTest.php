<?php

namespace Tests\Feature\Tags;

use App\Livewire\Experts\ExpertEditor;
use App\Livewire\Experts\ExpertList;
use App\Livewire\Projects\CreateProject;
use App\Livewire\Projects\EditProject;
use App\Livewire\Tags\TagFields;
use App\Models\Expert;
use App\Models\Project;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\TagValue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

class TagFieldsTest extends TestCase
{
    use RefreshDatabase;

    private TagCategory $condition;

    private TagValue $status;

    private TagValue $control;

    private TagCategory $gender;

    private TagValue $female;

    protected function setUp(): void
    {
        parent::setUp();

        $this->condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'condition']);
        $this->status = $this->condition->values()->create(['name' => 'status']);
        $this->control = $this->condition->values()->create(['name' => 'no-status']);
        $this->gender = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'Geschlecht']);
        $this->female = $this->gender->values()->create(['name' => 'weiblich']);
    }

    public function test_it_shows_one_select_per_category_of_its_own_scope(): void
    {
        $component = Livewire::test(TagFields::class, ['scope' => 'project']);

        $this->assertSame(['condition'], $component->viewData('categories')->pluck('name')->all());
        $component->assertSee('no-status')->assertDontSee('weiblich');
    }

    public function test_category_names_are_capitalised_as_labels_but_stay_as_stored(): void
    {
        Livewire::test(TagFields::class, ['scope' => 'project'])
            ->assertSee('Condition')
            ->assertDontSee('condition');

        $this->assertSame('condition', $this->condition->fresh()->name);
    }

    public function test_it_renders_nothing_without_categories(): void
    {
        TagCategory::query()->delete();

        Livewire::test(TagFields::class, ['scope' => 'persona'])->assertDontSeeHtml('<select');
    }

    public function test_creating_a_project_stores_the_selection(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateProject::class)
            ->set('title', 'Schule | with status | 100')
            ->set('description', 'Handyverbot an Schulen?')
            ->set('tagSelection', [$this->condition->id => (string) $this->status->id])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('status', Project::sole()->tagFor($this->condition)?->name);
    }

    public function test_editing_a_project_loads_and_changes_the_selection(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $project = Project::factory()->create(['user_id' => $owner->id]);
        $project->setTag($this->condition, $this->status);

        Livewire::test(EditProject::class, ['project' => $project])
            ->assertSet('tagSelection', [$this->condition->id => $this->status->id])
            ->set("tagSelection.{$this->condition->id}", (string) $this->control->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('no-status', $project->fresh()->tagFor($this->condition)?->name);

        Livewire::test(EditProject::class, ['project' => $project->fresh()])
            ->set("tagSelection.{$this->condition->id}", '')
            ->call('save');

        $this->assertNull($project->fresh()->tagFor($this->condition));
    }

    public function test_only_the_owner_may_change_a_projects_tags(): void
    {
        $owner = User::factory()->create();
        $reader = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id]);
        $project->addContributingUser($reader);
        $this->actingAs($reader);

        Livewire::test(EditProject::class, ['project' => $project])
            ->set('tagSelection', [$this->condition->id => (string) $this->status->id])
            ->call('save')
            ->assertForbidden();

        $this->assertNull($project->fresh()->tagFor($this->condition));
    }

    public function test_the_expert_editor_loads_and_saves_the_persona_tags(): void
    {
        $this->actingAs(User::factory()->create());
        $expert = Expert::factory()->create();

        Livewire::test(ExpertEditor::class)
            ->call('edit', $expert->id)
            ->assertSet('tagSelection', [])
            ->set('tagSelection', [$this->gender->id => (string) $this->female->id])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('weiblich', $expert->fresh()->tagFor($this->gender)?->name);

        Livewire::test(ExpertEditor::class)
            ->call('edit', $expert->id)
            ->assertSet('tagSelection', [$this->gender->id => $this->female->id]);
    }

    public function test_the_expert_list_shows_the_values_as_badges(): void
    {
        $this->actingAs(User::factory()->create());
        Expert::factory()->create(['name' => 'Ada'])->setTag($this->gender, $this->female);

        Livewire::test(ExpertList::class)
            ->assertSee('Geschlecht: weiblich')
            ->assertDontSeeHtml('text-zinc-400">Geschlecht:');
    }

    public function test_a_true_false_category_shows_category_and_value_for_both(): void
    {
        $this->actingAs(User::factory()->create());
        $titleHolder = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'title holder']);
        $true = $titleHolder->values()->create(['name' => 'true']);
        $false = $titleHolder->values()->create(['name' => 'false']);
        Expert::factory()->create(['name' => 'Ada'])->setTag($titleHolder, $true);
        Expert::factory()->create(['name' => 'Bo'])->setTag($titleHolder, $false);

        Livewire::test(ExpertList::class)
            ->assertSee('Title holder: true')
            ->assertSee('Title holder: false');
    }

    public function test_the_collapsed_tag_section_shows_the_current_values_or_none_set(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $project = Project::factory()->create(['user_id' => $owner->id]);

        Livewire::test(EditProject::class, ['project' => $project])
            ->assertSee(__('tags.form.none_set'))
            ->set('tagSelection', [$this->condition->id => (string) $this->status->id])
            ->assertDontSee(__('tags.form.none_set'))
            ->assertSee('Condition: status');

        Livewire::test(CreateProject::class)->assertSee(__('tags.form.none_set'));
    }

    public function test_a_forged_tag_value_leaves_no_half_created_project_behind(): void
    {
        $this->actingAs(User::factory()->create());

        $this->expectException(InvalidArgumentException::class);

        try {
            Livewire::test(CreateProject::class)
                ->set('title', 'Forged')
                ->set('description', 'Handyverbot an Schulen?')
                ->set('tagSelection', [$this->condition->id => (string) $this->gender->values()->first()->id])
                ->call('save');
        } finally {
            $this->assertSame(0, Project::count());
        }
    }

    public function test_a_forged_tag_value_in_the_expert_editor_creates_or_changes_nothing(): void
    {
        $this->actingAs(User::factory()->create());
        $expert = Expert::factory()->create(['name' => 'Ada']);
        $forged = [$this->gender->id => (string) $this->status->id];

        foreach ([null, $expert->id] as $id) {
            try {
                Livewire::test(ExpertEditor::class)
                    ->call('edit', $id)
                    ->set('name', 'Gefälscht')
                    ->set('role', 'Rolle')
                    ->set('tagSelection', $forged)
                    ->call('save');
                $this->fail('A forged value must be refused.');
            } catch (InvalidArgumentException) {
                // expected
            }
        }

        $this->assertSame(['Ada'], Expert::pluck('name')->all());
    }

    public function test_a_forged_tag_value_leaves_an_edited_project_unchanged(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner->id, 'title' => 'Alt']);
        $this->actingAs($owner);

        try {
            Livewire::test(EditProject::class, ['project' => $project])
                ->set('title', 'Neu')
                ->set('tagSelection', [$this->condition->id => (string) $this->female->id])
                ->call('save');
            $this->fail('A forged value must be refused.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame('Alt', $project->fresh()->title);
    }
}
