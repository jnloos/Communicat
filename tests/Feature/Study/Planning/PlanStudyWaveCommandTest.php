<?php

namespace Tests\Feature\Study\Planning;

use App\Models\Expert;
use App\Models\PersonaAttribute;
use App\Models\Project;
use App\Models\TagCategory;
use App\Models\User;
use App\Study\Models\StudyWave;
use App\Study\Planning\WavePlanning;
use App\Study\Planning\WaveRefused;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanStudyWaveCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        User::factory()->create(['is_admin' => true]);
    }

    public function test_a_dry_run_writes_nothing_and_shows_every_section_and_one_prompt_per_scenario(): void
    {
        $before = $this->counts();

        $command = $this->artisan('study:plan', ['manifest' => self::path('probe-manifest.json'), '--wave' => 1, '--scenarios' => '1-6', '--dry-run' => true])
            ->expectsOutputToContain('Runs')
            ->expectsOutputToContain('Personas')
            ->expectsOutputToContain('Focus seat')
            ->expectsOutputToContain('Focus subgroup')
            ->expectsOutputToContain('First names')
            ->expectsOutputToContain('Full names in two instances: 0');

        foreach (range(1, 6) as $scenario) {
            $command->expectsOutputToContain("Prompt, scenario {$scenario}");
        }
        // One write holds a whole prompt, and an expectation matches one write: check the topic only.
        $command->expectsOutputToContain('Should the bakery switch to a four-day week?')
            ->assertSuccessful();
        $command->run();

        $this->assertSame($before, $this->counts());
        $this->assertSame(0, StudyWave::count());
    }

    public function test_it_needs_exactly_one_of_dry_run_and_apply(): void
    {
        $arguments = ['manifest' => self::path('tiny-manifest.json'), '--wave' => 1, '--scenarios' => '1-1'];

        $this->artisan('study:plan', $arguments)->expectsOutputToContain('--dry-run or --apply')->assertFailed();
        $this->artisan('study:plan', $arguments + ['--dry-run' => true, '--apply' => true])->expectsOutputToContain('--dry-run or --apply')->assertFailed();
    }

    public function test_a_refused_wave_lists_its_reasons_and_fails(): void
    {
        $this->artisan('study:plan', ['manifest' => self::path('tiny-manifest.json'), '--wave' => 1, '--scenarios' => '2-2', '--dry-run' => true])
            ->expectsOutputToContain('scenarios 1 to 1')
            ->assertFailed();
    }

    public function test_apply_writes_the_wave_and_marks_it_applied(): void
    {
        $this->artisan('study:plan', ['manifest' => self::path('tiny-manifest.json'), '--wave' => 1, '--scenarios' => '1-1', '--apply' => true])
            ->expectsOutputToContain('Scenario 1 written')
            ->assertSuccessful();

        $wave = StudyWave::sole();
        $this->assertNotNull($wave->applied_at);
        $this->assertSame([1, 1, 'tiny'], [$wave->first_scenario, $wave->last_scenario, $wave->study]);
        $this->assertSame(64, Project::count());
    }

    public function test_resuming_a_wave_as_another_user_is_refused(): void
    {
        $planning = app(WavePlanning::class);
        $owner = User::factory()->create(['is_admin' => true]);
        $other = User::factory()->create(['is_admin' => true]);
        $prepared = $planning->prepare(ManifestTest::fixture('tiny-manifest.json'), 1, 1, 1, null);
        $planning->record($prepared, $owner);

        $this->assertSame($owner->id, $planning->record($prepared, $owner)->user_id);

        $this->expectException(WaveRefused::class);
        $this->expectExceptionMessage("resume it with that user, not {$other->id}");
        $planning->record($prepared, $other);
    }

    public static function path(string $fixture): string
    {
        return __DIR__.'/../fixtures/'.$fixture;
    }

    private function counts(): array
    {
        return [Project::count(), Expert::count(), TagCategory::count(), PersonaAttribute::count()];
    }
}
