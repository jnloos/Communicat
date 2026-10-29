<?php

namespace Tests\Feature\Discussion;

use App\Discussion\Support\PersonaLines;
use App\Discussion\Values\AttributeVisibility;
use App\Models\Expert;
use App\Models\PersonaAttribute;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\PromptFixtures;
use Tests\TestCase;

/**
 * Runs frozen before the role became an attribute: their cast entries still carry
 * `role`, their attributes list has no Role entry and their visibility is one
 * string. They must read as they did then (the golden prompts), and a run frozen
 * now must not touch the attribute tables to render a prompt.
 */
class PersonaLinesFrozenTest extends TestCase
{
    use PromptFixtures, RefreshDatabase;

    /** @param  list<array<string, mixed>>  $extraEntries  frozen attribute entries added to every seat */
    private function freezeAsLegacy(Project $project, string|array $visibility, array $extraEntries = []): Project
    {
        $cast = $project->contributingExperts()->map(fn (Expert $expert) => [
            'seat' => $expert->pivot->seat,
            'expert_id' => $expert->id,
            'name' => $expert->name,
            'role' => $expert->personaAttributes->first(fn (PersonaAttribute $a) => $a->isRole())->pivot->value,
            'attributes' => $extraEntries,
        ])->values()->all();

        $project->update(['run_config' => ['pipeline' => 'RoundRobinPipeline', 'attribute_visibility' => $visibility, 'cast' => $cast]]);

        return $project->fresh();
    }

    public static function legacyLevels(): array
    {
        return ['all' => ['all'], 'own' => ['own'], 'none' => ['none']];
    }

    /**
     * The old fixed role line showed the role to everyone whatever the level was,
     * so the pre-feature prompts are the answer at every level.
     */
    #[DataProvider('legacyLevels')]
    public function test_a_legacy_frozen_run_renders_the_pre_role_attribute_prompts(string $level): void
    {
        $project = $this->freezeAsLegacy($this->fixtureProject(AttributeVisibility::None), $level);

        foreach ($this->renderFixturePrompts($project) as $name => $text) {
            $this->assertSame(File::get(base_path("tests/Fixtures/prompts/{$name}.txt")), $text, "{$level}: {$name}");
        }
    }

    public function test_the_legacy_level_still_decides_every_other_attribute(): void
    {
        $gender = PersonaAttribute::factory()->create(['name' => 'gender', 'self_template' => 'You are a :value.', 'roster_template' => ':value']);
        $entry = ['attribute' => 'gender', 'value' => 'woman', 'self_line' => 'You are a woman.', 'roster' => 'woman'];
        $alice = fn (Project $project) => PersonaLines::for($project, Expert::findOrFail(11));
        $fixture = $this->fixtureProject();
        $gender->experts()->attach(11, ['value' => 'woman']);

        $none = $alice($this->freezeAsLegacy($fixture, 'none', [$entry]));
        $this->assertSame(['Your role is Student.'], $none->selfLines());
        $this->assertSame(' (Student)', $none->rosterLabel());

        $own = $alice($this->freezeAsLegacy($fixture, 'own', [$entry]));
        $this->assertSame(['Your role is Student.', 'You are a woman.'], $own->selfLines());
        $this->assertSame(' (Student)', $own->rosterLabel());

        $all = $alice($this->freezeAsLegacy($fixture, 'all', [$entry]));
        $this->assertSame(['Your role is Student.', 'You are a woman.'], $all->selfLines());
        $this->assertSame(' (Student, woman)', $all->rosterLabel());
    }

    public function test_the_bridge_uses_the_role_definitions_templates_and_falls_back_to_the_defaults(): void
    {
        $project = $this->freezeAsLegacy($this->fixtureProject(), 'all');
        PersonaAttribute::role()->update(['self_template' => 'You work as :value.']);

        $this->assertSame(['You work as Student.'], PersonaLines::for($project->fresh(), Expert::findOrFail(11))->selfLines());

        PersonaAttribute::query()->delete();
        $this->assertSame(['Your role is Student.'], PersonaLines::for($project->fresh(), Expert::findOrFail(11))->selfLines());
    }

    public function test_a_frozen_role_entry_is_never_doubled_by_the_bridge(): void
    {
        $project = $this->fixtureProject();
        $role = ['attribute' => 'Role', 'value' => 'Student', 'self_line' => 'Your role is Student.', 'roster' => 'Student'];
        $project = $this->freezeAsLegacy($project, ['Role' => 'all'], [$role]);

        $this->assertSame(['Your role is Student.'], PersonaLines::for($project, Expert::findOrFail(11))->selfLines());
    }

    public function test_a_run_frozen_now_renders_prompts_without_any_attribute_query(): void
    {
        $project = $this->fixtureProject();
        $levels = array_map(fn ($level) => $level->value, PersonaLines::liveVisibilitiesOf($project));
        $cast = $project->contributingExperts()->map(fn (Expert $expert) => [
            'seat' => $expert->pivot->seat,
            'expert_id' => $expert->id,
            'name' => $expert->name,
            'attributes' => PersonaLines::snapshotFor($expert),
        ])->values()->all();
        $project->update(['run_config' => ['attribute_visibility' => $levels, 'cast' => $cast]]);
        $project = $project->fresh();
        $expert = Expert::findOrFail(11);

        DB::enableQueryLog();
        for ($call = 0; $call < 5; $call++) {
            $lines = PersonaLines::for($project, $expert);
            $lines->selfLines();
            $lines->rosterLabel();
        }

        $this->assertSame([], DB::getQueryLog());
        $this->assertSame(['Your role is Student.'], PersonaLines::for($project, $expert)->selfLines());
    }

    public function test_a_legacy_string_run_costs_at_most_one_query_per_lookup(): void
    {
        $project = $this->freezeAsLegacy($this->fixtureProject(), 'all');
        $expert = Expert::findOrFail(11);

        DB::enableQueryLog();
        PersonaLines::for($project, $expert);

        $this->assertLessThanOrEqual(1, count(DB::getQueryLog()));
    }

    public function test_the_live_visibilities_cost_two_queries_and_the_ids_come_from_one_place(): void
    {
        $project = $this->fixtureProject(AttributeVisibility::Own);
        $project = $project->fresh();

        DB::enableQueryLog();
        $byName = PersonaLines::liveVisibilitiesOf($project);

        $this->assertLessThanOrEqual(2, count(DB::getQueryLog()));
        $this->assertSame(['Role' => AttributeVisibility::Own], $byName);
        $this->assertSame([PersonaAttribute::role()->id => AttributeVisibility::Own], $project->attributeVisibilities());
    }
}
