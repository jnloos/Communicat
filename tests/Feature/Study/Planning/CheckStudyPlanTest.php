<?php

namespace Tests\Feature\Study\Planning;

use App\Models\Expert;
use App\Models\PersonaAttribute;
use App\Models\Project;
use App\Models\StudyWave;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\User;
use App\Services\Study\Planning\WavePlanning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CheckStudyPlanTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::factory()->create(['is_admin' => true]);
        $planning = app(WavePlanning::class);
        $planning->apply($planning->record($planning->prepare(ManifestTest::fixture('tiny-manifest.json'), 1, 1, 1), $owner));
        $this->project = Project::orderBy('id')->firstOrFail();
    }

    public function test_a_clean_state_passes(): void
    {
        $this->artisan('study:check', ['--study' => 'tiny'])
            ->expectsOutputToContain('No deviations')
            ->assertSuccessful();
    }

    public function test_a_definition_named_in_another_case_is_the_same_attribute(): void
    {
        PersonaAttribute::where('name', 'Gender')->update(['name' => 'gender']);

        $this->artisan('study:check', ['--study' => 'tiny'])->expectsOutputToContain('No deviations')->assertSuccessful();
    }

    public function test_a_stored_wave_outlives_a_model_removed_from_the_config(): void
    {
        $manifest = StudyWave::sole()->manifest();
        $models = config('ai.models');
        unset($models[$manifest->model()]);
        config(['ai.models' => $models]);

        $this->artisan('study:check', ['--study' => 'tiny'])->expectsOutputToContain('No deviations')->assertSuccessful();
        $this->assertSame($manifest->sha256(), StudyWave::sole()->manifest()->sha256());
    }

    public function test_a_changed_seed_is_reported(): void
    {
        $this->project->update(['seed' => 7]);

        $this->expectDeviation('seed');
    }

    public function test_a_deleted_tag_is_reported(): void
    {
        $category = TagCategory::ofScope(TagScope::Project)->where('name', 'block')->firstOrFail();
        $this->project->setTag($category, null);

        $this->expectDeviation('tag block');
    }

    public function test_swapped_seats_are_reported(): void
    {
        $seats = DB::table('project_contributors')->where('project_id', $this->project->id)->where('contributor_type', (new Expert)->getMorphClass())->orderBy('seat')->get();
        DB::table('project_contributors')->where('id', $seats[0]->id)->update(['seat' => 2]);
        DB::table('project_contributors')->where('id', $seats[1]->id)->update(['seat' => 1]);

        $this->expectDeviation('expert uuids by seat');
    }

    public function test_a_foreign_project_of_the_study_is_reported(): void
    {
        $stray = Project::factory()->create(['title' => 'Stray cell']);
        $category = TagCategory::ofScope(TagScope::Project)->where('name', 'study')->firstOrFail();
        $stray->setTag($category, $category->values()->where('name', 'tiny')->firstOrFail());

        $this->expectDeviation('not in any plan');
    }

    public function test_a_wave_with_another_study_is_not_checked(): void
    {
        StudyWave::query()->update(['study' => 'other']);

        $this->artisan('study:check', ['--study' => 'tiny'])->expectsOutputToContain('No wave')->assertFailed();
    }

    private function expectDeviation(string $what): void
    {
        $this->artisan('study:check', ['--study' => 'tiny'])
            ->expectsOutputToContain($what)
            ->assertFailed();
    }
}
