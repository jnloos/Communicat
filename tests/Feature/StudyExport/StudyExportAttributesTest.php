<?php

namespace Tests\Feature\StudyExport;

use App\Discussion\Values\AttributeVisibility;
use App\Models\Expert;
use App\Models\PersonaAttribute;
use App\Models\Project;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\User;
use App\Services\StudyExport\StudyExport;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\TemporaryFiles;
use Tests\TestCase;
use ZipArchive;

class StudyExportAttributesTest extends TestCase
{
    use RefreshDatabase, TemporaryFiles;

    /** @return array<string, list<array<string, string>>> */
    private function export(array $runs, User $viewer): array
    {
        $path = $this->temporaryPath();
        app(StudyExport::class)->write(collect($runs), $viewer, $path);
        $zip = new ZipArchive;
        $zip->open($path);
        $files = [];

        foreach (['runs.csv', 'participants.csv'] as $file) {
            $stream = fopen('php://temp', 'r+');
            fwrite($stream, $zip->getFromName($file));
            rewind($stream);
            $header = fgetcsv($stream, escape: '');
            $rows = [];

            while (($line = fgetcsv($stream, escape: '')) !== false) {
                $rows[] = array_combine($header, $line);
            }

            $files[$file] = ['header' => $header, 'rows' => $rows];
        }

        $files['readme'] = $zip->getFromName('README.txt');

        return $files;
    }

    private function attribute(string $name): PersonaAttribute
    {
        return PersonaAttribute::create(['name' => $name, 'self_template' => ':value', 'roster_template' => ':value']);
    }

    public function test_participants_get_a_column_per_definition_with_empty_gaps(): void
    {
        $viewer = User::factory()->create();
        $origin = $this->attribute('origin');
        $this->attribute('age');
        $run = Project::factory()->create(['user_id' => $viewer->id]);
        $withValue = Expert::factory()->create();
        $withValue->setAttributeValue($origin, 'Lagos');
        $run->addContributingExpert($withValue);
        $run->addContributingExpert(Expert::factory()->create());

        $files = $this->export([$run], $viewer);

        $this->assertSame(['origin', 'age'], array_slice($files['participants.csv']['header'], 5, 2));
        $this->assertSame(['Lagos', ''], [$files['participants.csv']['rows'][0]['origin'], $files['participants.csv']['rows'][0]['age']]);
        $this->assertSame(['', ''], [$files['participants.csv']['rows'][1]['origin'], $files['participants.csv']['rows'][1]['age']]);
        $this->assertStringContainsString('attribute_visibility', $files['readme']);
    }

    public function test_a_name_clashing_with_a_fixed_or_tag_column_is_prefixed_and_never_overwrites(): void
    {
        $viewer = User::factory()->create();
        TagCategory::create(['scope' => TagScope::Persona, 'name' => 'origin']);
        $role = $this->attribute('seat');
        $origin = $this->attribute('Origin');
        $this->attribute('attribute origin');
        $run = Project::factory()->create(['user_id' => $viewer->id]);
        $expert = Expert::factory()->create();
        $expert->setAttributeValue($role, 'x');
        $expert->setAttributeValue($origin, 'Lagos');
        $run->addContributingExpert($expert);

        $header = $this->export([$run], $viewer)['participants.csv']['header'];

        $this->assertSame($header, array_values(array_unique($header)));
        $this->assertContains('attribute seat', $header);
        $this->assertContains('attribute Origin', $header);
        $this->assertCount(1, array_filter($header, fn ($h) => $h === 'origin'));
        $this->assertContains('attribute attribute origin', $header);
    }

    public function test_runs_carry_the_frozen_visibility_else_the_projects_as_one_cell(): void
    {
        $viewer = User::factory()->create();
        $role = PersonaAttribute::role();
        $gender = $this->attribute('gender');
        $frozen = Project::factory()->create(['user_id' => $viewer->id, 'run_config' => ['attribute_visibility' => ['Role' => 'all', 'gender' => 'none']]]);
        $frozen->setAttributeVisibilities([$gender->id => AttributeVisibility::Own]);
        $legacy = Project::factory()->create(['user_id' => $viewer->id, 'run_config' => ['attribute_visibility' => 'own']]);
        $live = Project::factory()->create(['user_id' => $viewer->id]);
        $live->setAttributeVisibilities([$role->id => AttributeVisibility::All, $gender->id => AttributeVisibility::Own]);

        $rows = collect($this->export([$frozen, $legacy, $live], $viewer)['runs.csv']['rows'])->pluck('attribute_visibility', 'run_uuid');

        $this->assertSame('Role=all; gender=none', $rows[$frozen->uuid]);
        $this->assertSame('Role=own; gender=own', $rows[$legacy->uuid]);
        $this->assertSame('Role=all; gender=own', $rows[$live->uuid]);
    }

    public function test_the_visibility_cell_is_empty_without_definitions(): void
    {
        $viewer = User::factory()->create();
        $run = Project::factory()->create(['user_id' => $viewer->id]);

        $rows = collect($this->export([$run], $viewer)['runs.csv']['rows'])->pluck('attribute_visibility', 'run_uuid');

        $this->assertSame('', $rows[$run->uuid]);
    }

    public function test_the_attribute_values_are_read_in_one_query_however_many_runs(): void
    {
        $viewer = User::factory()->create();
        $origin = $this->attribute('origin');
        $runs = Project::factory()->count(3)->create(['user_id' => $viewer->id])->each(function (Project $run) use ($origin) {
            $expert = Expert::factory()->create();
            $expert->setAttributeValue($origin, 'Lagos');
            $run->addContributingExpert($expert);
            $run->addContributingExpert(Expert::factory()->create());
        });
        $queries = 0;
        DB::listen(function (QueryExecuted $query) use (&$queries) {
            if (str_contains($query->sql, 'from "expert_persona_attribute"') || str_contains($query->sql, 'inner join "expert_persona_attribute"')) {
                $queries++;
            }
        });

        $this->export($runs->all(), $viewer);

        $this->assertSame(1, $queries);
    }

    public function test_the_visibility_cells_cost_no_query_per_run(): void
    {
        $viewer = User::factory()->create();
        $role = PersonaAttribute::role();
        $this->attribute('gender');
        $queriesFor = function (int $runs) use ($viewer, $role): int {
            $made = Project::factory()->count($runs)->create(['user_id' => $viewer->id])
                ->each(fn (Project $run) => $run->setAttributeVisibilities([$role->id => AttributeVisibility::All]));
            $made[0]->update(['run_config' => ['attribute_visibility' => 'own']]);
            $count = 0;
            DB::listen(function (QueryExecuted $query) use (&$count) {
                if (str_contains($query->sql, 'persona_attributes') && ! str_contains($query->sql, 'expert_persona_attribute')
                    || str_contains($query->sql, 'project_attribute_visibility')) {
                    $count++;
                }
            });

            $this->export($made->all(), $viewer);

            return $count;
        };

        $few = $queriesFor(2);
        $many = $queriesFor(6);

        $this->assertGreaterThan(0, $few);
        $this->assertSame($few, $many);
    }
}
