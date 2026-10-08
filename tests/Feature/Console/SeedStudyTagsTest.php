<?php

namespace Tests\Feature\Console;

use App\Models\Expert;
use App\Models\Project;
use App\Models\TagCategory;
use App\Models\Taggable;
use App\Models\TagScope;
use App\Services\Tags\StudyTagSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeedStudyTagsTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<Expert>  $seats  in seat order, seat 1 first */
    private function cell(string $title, array $seats): Project
    {
        $project = Project::factory()->create(['title' => $title]);

        foreach ($seats as $expert) {
            $project->addContributingExpert($expert);
        }

        return $project;
    }

    private function tagOf(Project|Expert $model, TagScope $scope, string $category): ?string
    {
        $category = TagCategory::ofScope($scope)->where('name', $category)->first();

        return $category === null ? null : $model->fresh()->tagFor($category)?->name;
    }

    public function test_the_dry_run_shows_the_plan_and_writes_nothing(): void
    {
        [$ceo, $other] = Expert::factory()->count(2)->create();
        $this->cell('School | with status | 60', [$ceo, $other]);

        $this->artisan('tags:seed-study')
            ->expectsOutputToContain('School | with status | 60')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame(0, TagCategory::count());
        $this->assertSame(0, Taggable::count());
    }

    public function test_apply_tags_condition_and_title_holder_and_is_idempotent(): void
    {
        [$ceo, $peer, $neutral] = Expert::factory()->count(3)->create();
        $status = $this->cell('School | with status | 60', [$ceo, $peer]);
        $control = $this->cell('Traffic | without status | 60', [$neutral, $peer]);
        $untitled = $this->cell('Pilot', [$peer]);

        $this->artisan('tags:seed-study', ['--apply' => true])->assertSuccessful();
        $this->artisan('tags:seed-study', ['--apply' => true])->assertSuccessful();

        $this->assertSame('status', $this->tagOf($status, TagScope::Project, 'bedingung'));
        $this->assertSame('kontrolle', $this->tagOf($control, TagScope::Project, 'bedingung'));
        $this->assertNull($this->tagOf($untitled, TagScope::Project, 'bedingung'));
        $this->assertSame('ja', $this->tagOf($ceo, TagScope::Persona, 'titeltraeger'));
        $this->assertNull($this->tagOf($neutral, TagScope::Persona, 'titeltraeger'));
        $this->assertSame(2, TagCategory::count());
        $this->assertSame(3, Taggable::count());
    }

    public function test_a_title_holder_who_also_sits_in_a_control_cell_is_reported_not_tagged(): void
    {
        [$both, $peer] = Expert::factory()->count(2)->create();
        $this->cell('School | with status | 60', [$both, $peer]);
        $this->cell('School | without status | 60', [$peer, $both]);

        $this->artisan('tags:seed-study', ['--apply' => true])
            ->expectsOutputToContain('Conflict')
            ->assertSuccessful();

        $this->assertNull($this->tagOf($both, TagScope::Persona, 'titeltraeger'));
    }

    public function test_it_reads_the_condition_from_the_real_title_formats(): void
    {
        $seeder = new StudyTagSeeder;

        $this->assertSame('status', $seeder->conditionFor('School | with status | 60'));
        $this->assertSame('kontrolle', $seeder->conditionFor('School | without status | 60'));
        $this->assertSame('status', $seeder->conditionFor('Score | School | with status | 100'));
        $this->assertSame('kontrolle', $seeder->conditionFor('Score | School | without status | 100'));
        $this->assertNull($seeder->conditionFor('Test'));
    }
}
