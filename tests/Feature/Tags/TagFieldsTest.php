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

        $this->condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'bedingung']);
        $this->status = $this->condition->values()->create(['name' => 'status']);
        $this->control = $this->condition->values()->create(['name' => 'kontrolle']);
        $this->gender = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'Geschlecht']);
        $this->female = $this->gender->values()->create(['name' => 'weiblich']);
    }

    public function test_it_shows_one_select_per_category_of_its_own_scope(): void
    {
        $component = Livewire::test(TagFields::class, ['scope' => 'project']);

        $this->assertSame(['bedingung'], $component->viewData('categories')->pluck('name')->all());
        $component->assertSee('kontrolle')->assertDontSee('weiblich');
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

        $this->assertSame('kontrolle', $project->fresh()->tagFor($this->condition)?->name);

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

        Livewire::test(ExpertList::class)->assertSee('weiblich');
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
}
