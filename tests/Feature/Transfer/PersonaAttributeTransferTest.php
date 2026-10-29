<?php

namespace Tests\Feature\Transfer;

use App\Discussion\Values\AttributeVisibility;
use App\Models\Expert;
use App\Models\PersonaAttribute;
use App\Models\Project;
use App\Models\User;
use App\Services\Transfer\Bundle;
use App\Services\Transfer\BundleExporter;
use App\Services\Transfer\BundleImporter;
use App\Services\Transfer\ExpertTransfer;
use App\Services\Transfer\ImportReport;
use App\Services\Transfer\PersonaAttributeTransfer;
use App\Services\Transfer\ProjectTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\TemporaryFiles;
use Tests\TestCase;
use ZipArchive;

class PersonaAttributeTransferTest extends TestCase
{
    use RefreshDatabase, TemporaryFiles;

    private function attributes(): array
    {
        return [
            PersonaAttribute::create(['name' => 'origin', 'self_template' => 'You grew up in :value.', 'roster_template' => 'from :value']),
            PersonaAttribute::create(['name' => 'gender', 'self_template' => 'You are :value.', 'roster_template' => ':value']),
        ];
    }

    private function wipe(): void
    {
        foreach (['expert_persona_attribute', 'persona_attributes', 'project_contributors', 'job_logs', 'projects', 'taggables', 'experts'] as $table) {
            DB::table($table)->delete();
        }
    }

    private function entries(string $path): array
    {
        $zip = new ZipArchive;
        $zip->open($path);

        return array_map(fn (int $i) => $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
    }

    public function test_a_bundle_round_trip_restores_definitions_values_visibility_and_order(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [$origin, $gender] = $this->attributes();
        $origin->moveTo(1);
        $expert = Expert::factory()->create();
        $expert->setAttributeValue($origin, 'Owerri, Nigeria');
        $project = Project::factory()->create(['user_id' => $admin->id, 'run_config' => ['pipeline' => 'RoundRobinPipeline', 'attribute_visibility' => ['origin' => 'all'], 'cast' => [['seat' => 1, 'expert_id' => $expert->id, 'name' => 'A', 'role' => 'R', 'attributes' => [['name' => 'origin', 'value' => 'Owerri, Nigeria', 'self_line' => 'You grew up in Owerri, Nigeria.', 'roster' => 'from Owerri, Nigeria']]]]]]);
        $project->addContributingExpert($expert);
        $project->setAttributeVisibilities([$origin->id => AttributeVisibility::All, $gender->id => AttributeVisibility::Own]);
        $path = $this->temporaryPath();
        app(BundleExporter::class)->everything($admin, $path);
        $this->assertContains(Bundle::PERSONA_ATTRIBUTES, $this->entries($path));
        $this->wipe();

        $report = app(BundleImporter::class)->import($path, $admin);

        $this->assertFalse($report->hasFailures());
        $this->assertSame(2, $report->count(ImportReport::PERSONA_ATTRIBUTES, ImportReport::CREATED));
        $this->assertSame(['gender', 'origin'], PersonaAttribute::orderBy('position')->pluck('name')->all());
        $this->assertSame('from :value', PersonaAttribute::where('name', 'origin')->sole()->roster_template);
        $copy = Expert::sole();
        $this->assertSame(['origin' => 'Owerri, Nigeria'], $copy->personaAttributes->mapWithKeys(fn ($a) => [$a->name => $a->pivot->value])->all());
        $imported = Project::where('uuid', $project->uuid)->sole();
        $this->assertEquals(['origin' => AttributeVisibility::All, 'gender' => AttributeVisibility::Own], $this->levelsByName($imported));
        $this->assertSame($project->run_config, $imported->run_config);
    }

    public function test_frozen_attributes_in_the_cast_stay_untouched_when_the_expert_is_renumbered(): void
    {
        $owner = User::factory()->create();
        $expert = Expert::factory()->create();
        $cast = [['seat' => 1, 'expert_id' => $expert->id, 'name' => 'A', 'role' => 'R', 'attributes' => [['name' => 'origin', 'value' => 'E1 town', 'self_line' => 'You live in E1 town.', 'roster' => 'E1 town']]]];
        $project = Project::factory()->create(['user_id' => $owner->id, 'run_config' => ['pipeline' => 'ScorePipeline', 'attribute_visibility' => ['origin' => 'own'], 'cast' => $cast]]);
        $project->addContributingExpert($expert);
        $data = (new ProjectTransfer)->export($project->fresh());
        $experts = [(new ExpertTransfer)->export($expert)];
        Project::query()->delete();
        Expert::query()->delete();
        Expert::factory()->create(['id' => $expert->id]);

        (new ExpertTransfer)->importAll($experts, new ImportReport);
        $copy = (new ProjectTransfer)->import($data, $owner, new ImportReport);

        $newId = Expert::where('uuid', $expert->uuid)->sole()->id;
        $this->assertNotSame($expert->id, $newId);
        $frozen = $copy->run_config['cast'][0];
        $this->assertSame($newId, $frozen['expert_id']);
        $this->assertSame($cast[0]['attributes'], $frozen['attributes']);
        $this->assertSame(['origin' => 'own'], $copy->run_config['attribute_visibility']);
    }

    public function test_a_bundle_without_definitions_has_no_entry_and_an_old_bundle_imports_unchanged(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $expert = Expert::factory()->create();
        $path = $this->temporaryPath();
        app(BundleExporter::class)->expert($expert, $path);
        $this->assertNotContains(Bundle::PERSONA_ATTRIBUTES, $this->entries($path));
        $this->assertArrayNotHasKey('attributes', (new ExpertTransfer)->export($expert));
        $this->wipe();

        $report = app(BundleImporter::class)->import($path, $admin);

        $this->assertSame([ImportReport::EXPERTS], $report->sections());
        $this->assertSame(1, Expert::count());
    }

    /** @return array<string, AttributeVisibility> */
    private function levelsByName(Project $project): array
    {
        $byId = $project->attributeVisibilities();

        return PersonaAttribute::ordered()->get()->mapWithKeys(fn ($a) => [$a->name => $byId[$a->id]])->all();
    }

    private function projectFile(User $owner, array $levels): array
    {
        [$origin, $gender] = $this->attributes();
        $project = Project::factory()->create(['user_id' => $owner->id]);
        $project->setAttributeVisibilities([$origin->id => $levels['origin'] ?? AttributeVisibility::None, $gender->id => $levels['gender'] ?? AttributeVisibility::None]);
        $data = (new ProjectTransfer)->export($project->fresh());
        Project::query()->delete();

        return $data;
    }

    public function test_the_project_file_carries_the_live_levels_by_attribute_name(): void
    {
        $data = $this->projectFile(User::factory()->create(), ['origin' => AttributeVisibility::All, 'gender' => AttributeVisibility::Own]);

        $this->assertSame(['origin' => 'all', 'gender' => 'own'], $data['attribute_visibility']);
    }

    public function test_levels_are_matched_by_name_ignoring_case_and_unknown_names_are_skipped(): void
    {
        $owner = User::factory()->create();
        $data = $this->projectFile($owner, []);
        $data['attribute_visibility'] = [' Origin ' => 'own', 'shoe size' => 'all'];

        $copy = (new ProjectTransfer)->import($data, $owner, $report = new ImportReport);

        $this->assertSame(['origin' => AttributeVisibility::Own, 'gender' => AttributeVisibility::None], $this->levelsByName($copy));
        $this->assertSame(0, $report->count(ImportReport::PROJECTS, ImportReport::FAILED));
    }

    public function test_a_single_level_from_an_early_bundle_counts_for_every_attribute(): void
    {
        $owner = User::factory()->create();
        $data = $this->projectFile($owner, []);
        $data['attribute_visibility'] = 'own';

        $copy = (new ProjectTransfer)->import($data, $owner, new ImportReport);

        $this->assertSame(['origin' => AttributeVisibility::Own, 'gender' => AttributeVisibility::Own], $this->levelsByName($copy));
    }

    public function test_a_single_level_from_an_early_bundle_keeps_the_role_at_all(): void
    {
        $owner = User::factory()->create();
        $data = $this->projectFile($owner, []);
        PersonaAttribute::role();
        $data['attribute_visibility'] = 'none';

        $copy = (new ProjectTransfer)->import($data, $owner, new ImportReport);

        $this->assertSame(
            ['Role' => AttributeVisibility::All, 'origin' => AttributeVisibility::None, 'gender' => AttributeVisibility::None],
            $this->levelsByName($copy),
            'in those bundles the role was a fixed prompt line shown to everyone',
        );
    }

    public function test_a_project_file_without_visibility_imports_every_attribute_as_none(): void
    {
        $owner = User::factory()->create();
        $data = $this->projectFile($owner, ['origin' => AttributeVisibility::All]);
        unset($data['attribute_visibility']);

        $copy = (new ProjectTransfer)->import($data, $owner, new ImportReport);

        $this->assertSame(['origin' => AttributeVisibility::None, 'gender' => AttributeVisibility::None], $this->levelsByName($copy));
    }

    public function test_an_unknown_visibility_fails_that_project_only(): void
    {
        $owner = User::factory()->create();
        $data = $this->projectFile($owner, []);
        $report = new ImportReport;

        foreach (['everything', ['all'], ['origin' => 'everything'], ['origin' => ['all']]] as $bad) {
            $this->assertNull((new ProjectTransfer)->import(['attribute_visibility' => $bad] + $data, $owner, $report));
        }

        $this->assertSame(4, $report->count(ImportReport::PROJECTS, ImportReport::FAILED));
        $this->assertSame(0, Project::count());
    }

    public function test_an_existing_definition_keeps_its_templates_and_a_new_one_goes_to_the_end(): void
    {
        PersonaAttribute::create(['name' => 'Origin', 'self_template' => 'Local :value.', 'roster_template' => 'local :value']);
        $report = new ImportReport;

        (new PersonaAttributeTransfer)->import([
            ['name' => ' origin ', 'self_template' => 'Other :value.', 'roster_template' => 'other :value', 'position' => 1],
            ['name' => 'age', 'self_template' => 'You are :value.', 'roster_template' => ':value years', 'position' => 1],
        ], $report);

        $this->assertSame(1, $report->count(ImportReport::PERSONA_ATTRIBUTES, ImportReport::SKIPPED));
        $this->assertSame(1, $report->count(ImportReport::PERSONA_ATTRIBUTES, ImportReport::CREATED));
        $this->assertSame('Local :value.', PersonaAttribute::where('name', 'Origin')->sole()->self_template);
        $this->assertSame(['Origin', 'age'], PersonaAttribute::orderBy('position')->pluck('name')->all());
    }

    public function test_unreadable_or_placeholderless_definitions_fail_alone(): void
    {
        $report = new ImportReport;

        (new PersonaAttributeTransfer)->import([
            'nonsense',
            ['name' => 'a', 'self_template' => 'no placeholder', 'roster_template' => ':value'],
            ['name' => 'b', 'self_template' => ['x'], 'roster_template' => ':value'],
            ['name' => 'ok', 'self_template' => ':value', 'roster_template' => ':value'],
        ], $report);

        $this->assertSame(3, $report->count(ImportReport::PERSONA_ATTRIBUTES, ImportReport::FAILED));
        $this->assertSame(['ok'], PersonaAttribute::pluck('name')->all());
    }

    private function expertData(array $attributes): array
    {
        return ['uuid' => '0b9f2d4c-1a3e-4f5b-8c6d-7e8f9a0b1c2d', 'id' => 501, 'name' => 'Kim', 'description' => 'd', 'attributes' => $attributes];
    }

    public function test_an_empty_value_is_skipped_silently(): void
    {
        $this->attributes();
        $report = new ImportReport;

        (new ExpertTransfer)->import($this->expertData(['origin' => '', 'gender' => '   ']), $report);

        $this->assertSame(1, $report->count(ImportReport::EXPERTS, ImportReport::CREATED));
        $this->assertNull($report->entries(ImportReport::EXPERTS)[0]['note']);
        $this->assertSame(0, DB::table('expert_persona_attribute')->count());
    }

    public function test_a_value_over_the_limit_or_with_a_line_break_is_skipped_and_named_without_stopping_the_import(): void
    {
        $this->attributes();
        $long = str_repeat('x', PersonaAttribute::VALUE_MAX + 1);
        $report = new ImportReport;

        (new ExpertTransfer)->import($this->expertData(['origin' => $long, 'gender' => "wo\nman", 'Unknown' => 'v']), $report);

        $entry = $report->entries(ImportReport::EXPERTS)[0];
        $this->assertSame(ImportReport::CREATED, $entry['outcome']);
        $this->assertStringContainsString('Value too long', $entry['note']);
        $this->assertStringContainsString("origin: {$long}", $entry['note']);
        $this->assertStringContainsString('Value contains a line break', $entry['note']);
        $this->assertStringContainsString('Attributes not set: Unknown: v', $entry['note']);
        $this->assertSame(0, DB::table('expert_persona_attribute')->count());
        $this->assertSame(1, Expert::count());
    }

    public function test_a_good_value_next_to_a_bad_one_is_still_set(): void
    {
        $this->attributes();
        $report = new ImportReport;

        (new ExpertTransfer)->import($this->expertData(['origin' => str_repeat('x', PersonaAttribute::VALUE_MAX + 1), 'gender' => 'woman']), $report);

        $this->assertSame(['gender' => 'woman'], Expert::sole()->personaAttributes->mapWithKeys(fn ($a) => [$a->name => $a->pivot->value])->all());
    }

    public function test_a_definition_over_the_limits_or_with_a_line_break_fails_alone(): void
    {
        $report = new ImportReport;

        (new PersonaAttributeTransfer)->import([
            ['name' => str_repeat('n', PersonaAttribute::NAME_MAX + 1), 'self_template' => ':value', 'roster_template' => ':value'],
            ['name' => 'long self', 'self_template' => str_repeat('s', PersonaAttribute::TEMPLATE_MAX).':value', 'roster_template' => ':value'],
            ['name' => 'long roster', 'self_template' => ':value', 'roster_template' => ':value'.str_repeat('r', PersonaAttribute::TEMPLATE_MAX)],
            ['name' => "line\nbreak", 'self_template' => ':value', 'roster_template' => ':value'],
            ['name' => 'two lines', 'self_template' => "a\r\n:value", 'roster_template' => ':value'],
            ['name' => 'exactly', 'self_template' => str_repeat('s', PersonaAttribute::TEMPLATE_MAX - 6).':value', 'roster_template' => ':value'],
        ], $report);

        $this->assertSame(5, $report->count(ImportReport::PERSONA_ATTRIBUTES, ImportReport::FAILED));
        $this->assertSame(1, $report->count(ImportReport::PERSONA_ATTRIBUTES, ImportReport::CREATED));
        $this->assertSame(['exactly'], PersonaAttribute::pluck('name')->all());
        $notes = array_column($report->entries(ImportReport::PERSONA_ATTRIBUTES), 'note');
        $this->assertStringContainsString('Value too long', $notes[0]);
        $this->assertStringContainsString('line break', $notes[3]);
    }

    public function test_the_export_has_no_position_and_the_file_order_is_the_order(): void
    {
        PersonaAttribute::create(['name' => 'second', 'self_template' => ':value', 'roster_template' => ':value'])->moveTo(1);
        PersonaAttribute::create(['name' => 'first', 'self_template' => ':value', 'roster_template' => ':value'])->moveTo(0);

        $export = (new PersonaAttributeTransfer)->export();

        $this->assertSame(['first', 'second'], array_column($export, 'name'));
        $this->assertSame([], array_filter($export, fn (array $row) => array_key_exists('position', $row)));

        DB::table('persona_attributes')->delete();
        (new PersonaAttributeTransfer)->import($export, new ImportReport);

        $this->assertSame(['first', 'second'], PersonaAttribute::ordered()->pluck('name')->all());
    }

    public function test_an_old_bundle_with_a_position_still_imports_and_the_position_is_ignored(): void
    {
        $report = new ImportReport;

        (new PersonaAttributeTransfer)->import([
            ['name' => 'b', 'self_template' => ':value', 'roster_template' => ':value', 'position' => 9],
            ['name' => 'a', 'self_template' => ':value', 'roster_template' => ':value', 'position' => 1],
        ], $report);

        $this->assertSame(2, $report->count(ImportReport::PERSONA_ATTRIBUTES, ImportReport::CREATED));
        $this->assertSame(['b', 'a'], PersonaAttribute::ordered()->pluck('name')->all());
    }

    public function test_skipping_an_existing_definition_says_its_templates_are_unchanged(): void
    {
        PersonaAttribute::create(['name' => 'origin', 'self_template' => ':value', 'roster_template' => ':value']);
        $report = new ImportReport;

        (new PersonaAttributeTransfer)->import([['name' => 'origin', 'self_template' => 'x :value', 'roster_template' => 'y :value']], $report);

        $this->assertSame('Already in the database, templates unchanged.', $report->entries(ImportReport::PERSONA_ATTRIBUTES)[0]['note']);
    }

    public function test_a_value_without_definition_is_skipped_and_reported(): void
    {
        $this->attributes();
        $data = [
            'uuid' => '0b9f2d4c-1a3e-4f5b-8c6d-7e8f9a0b1c2d', 'id' => 501, 'name' => 'Kim', 'description' => 'd',
            'attributes' => ['Origin' => 'Lagos', 'religion' => 'none'],
        ];
        $report = new ImportReport;

        (new ExpertTransfer)->import($data, $report);

        $expert = Expert::where('uuid', $data['uuid'])->sole();
        $this->assertSame(['origin' => 'Lagos'], $expert->personaAttributes->mapWithKeys(fn ($a) => [$a->name => $a->pivot->value])->all());
        $this->assertStringContainsString('religion: none', $report->entries(ImportReport::EXPERTS)[0]['note']);
    }

    public function test_attributes_of_the_wrong_type_make_the_expert_unreadable(): void
    {
        $report = new ImportReport;

        (new ExpertTransfer)->import(['uuid' => '0b9f2d4c-1a3e-4f5b-8c6d-7e8f9a0b1c2d', 'name' => 'Kim', 'attributes' => 'Lagos'], $report);
        (new ExpertTransfer)->import(['uuid' => '1b9f2d4c-1a3e-4f5b-8c6d-7e8f9a0b1c2d', 'name' => 'Kim', 'attributes' => ['origin' => ['Lagos']]], $report);

        $this->assertSame(2, $report->count(ImportReport::EXPERTS, ImportReport::FAILED));
        $this->assertSame(0, Expert::count());
    }

    public function test_an_expert_already_here_keeps_its_attributes(): void
    {
        [$origin] = $this->attributes();
        $expert = Expert::factory()->create();
        $expert->setAttributeValue($origin, 'Lagos');
        $data = (new ExpertTransfer)->export($expert);
        $data['attributes'] = ['origin' => 'Berlin'];
        $report = new ImportReport;

        (new ExpertTransfer)->import($data, $report);

        $this->assertSame(1, $report->count(ImportReport::EXPERTS, ImportReport::SKIPPED));
        $this->assertSame('Lagos', $expert->personaAttributes()->first()->pivot->value);
    }

    public function test_a_preview_writes_no_definition_and_no_value(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [$origin] = $this->attributes();
        Expert::factory()->create()->setAttributeValue($origin, 'Lagos');
        $path = $this->temporaryPath();
        app(BundleExporter::class)->everything($admin, $path);
        $this->wipe();

        $preview = app(BundleImporter::class)->preview($path, $admin);

        $this->assertSame(2, $preview->count(ImportReport::PERSONA_ATTRIBUTES, ImportReport::CREATED));
        $this->assertSame(0, PersonaAttribute::count());
        $this->assertSame(0, DB::table('expert_persona_attribute')->count());
        $this->assertSame(0, Expert::count());
    }

    public function test_an_old_bundle_role_becomes_the_role_attribute_and_creates_the_definition(): void
    {
        $this->assertSame(0, PersonaAttribute::where('name', PersonaAttribute::ROLE)->count());
        $report = new ImportReport;

        (new ExpertTransfer)->import($this->expertData([]) + ['role' => 'Lead'], $report);

        $this->assertSame(1, $report->count(ImportReport::EXPERTS, ImportReport::CREATED));
        $this->assertSame(1, PersonaAttribute::where('name', PersonaAttribute::ROLE)->count());
        $this->assertSame(['Role' => 'Lead'], Expert::sole()->personaAttributes->mapWithKeys(fn ($a) => [$a->name => $a->pivot->value])->all());
    }

    public function test_an_old_bundle_role_reuses_an_existing_definition_ignoring_case(): void
    {
        $existing = PersonaAttribute::create(['name' => 'role', 'self_template' => 'You are :value.', 'roster_template' => ':value']);

        (new ExpertTransfer)->import($this->expertData([]) + ['role' => 'Lead'], new ImportReport);

        $this->assertSame([$existing->id], PersonaAttribute::pluck('id')->all());
        $this->assertSame('Lead', Expert::sole()->personaAttributes->sole()->pivot->value);
    }

    public function test_the_entrys_own_role_attribute_wins_over_the_old_role_field(): void
    {
        PersonaAttribute::role();

        (new ExpertTransfer)->import($this->expertData(['Role' => 'Captain']) + ['role' => 'Lead'], new ImportReport);

        $this->assertSame(['Role' => 'Captain'], Expert::sole()->personaAttributes->mapWithKeys(fn ($a) => [$a->name => $a->pivot->value])->all());
    }

    public function test_an_export_carries_the_role_as_an_attribute_and_no_role_field(): void
    {
        $expert = Expert::factory()->withRole('Teacher')->create();

        $data = (new ExpertTransfer)->export($expert);

        $this->assertArrayNotHasKey('role', $data);
        $this->assertSame(['Role' => 'Teacher'], $data['attributes']);
    }
}
