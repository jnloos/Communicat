<?php

namespace Tests\Feature\Study\Planning;

use App\Models\StudyWave;
use App\Services\Study\Planning\Manifest;
use App\Services\Study\Planning\WaveRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WaveRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_wave_1_must_start_at_scenario_1(): void
    {
        $this->assertSame([], $this->problems($this->manifest(), 1, 1, 3));
        $this->assertStringContainsString('Wave 1 must start at scenario 1', implode("\n", $this->problems($this->manifest(), 1, 2, 3)));
    }

    public function test_the_range_must_lie_inside_the_file(): void
    {
        $this->assertStringContainsString('scenarios 1 to 6', implode("\n", $this->problems($this->manifest(), 1, 1, 7)));
        $this->assertStringContainsString('scenarios 1 to 6', implode("\n", $this->problems($this->manifest(), 1, 3, 2)));
    }

    public function test_wave_2_needs_an_applied_wave_1_and_the_next_scenario(): void
    {
        $manifest = $this->manifest();
        $this->assertStringContainsString('Wave 1 does not exist', implode("\n", $this->problems($manifest, 2, 4, 6)));

        $first = $this->record($manifest, 1, 1, 3);
        $this->assertStringContainsString('Wave 1 is not applied yet', implode("\n", $this->problems($manifest, 2, 4, 6)));

        $first->update(['applied_at' => now()]);
        $this->assertSame([], $this->problems($manifest, 2, 4, 6));
        $this->assertStringContainsString('must start at scenario 4', implode("\n", $this->problems($manifest, 2, 5, 6)));
    }

    public function test_the_rules_read_the_stored_wave_even_if_its_model_left_the_config(): void
    {
        $manifest = $this->manifest();
        $this->record($manifest, 1, 1, 3)->update(['applied_at' => now()]);
        $models = config('ai.models');
        unset($models[$manifest->model()]);
        config(['ai.models' => $models]);

        $this->assertSame([], $this->problems($manifest, 2, 4, 6));
    }

    public function test_a_changed_core_or_planned_scenario_is_rejected_an_unplanned_change_accepted(): void
    {
        $this->record($this->manifest(), 1, 1, 3)->update(['applied_at' => now()]);

        $core = $this->manifest(fn (array $m) => ['turn_budget' => 40] + $m);
        $this->assertStringContainsString('core differs', implode("\n", $this->problems($core, 2, 4, 6)));

        $third = $this->manifest(function (array $m) {
            $m['scenarios'][2]['topic'] = 'Something else?';

            return $m;
        });
        $this->assertStringContainsString('Scenario 3 differs', implode("\n", $this->problems($third, 2, 4, 6)));

        $fifth = $this->manifest(function (array $m) {
            $m['scenarios'][4]['topic'] = 'Something else?';

            return $m;
        });
        $this->assertSame([], $this->problems($fifth, 2, 4, 6));
    }

    public function test_the_same_wave_again_is_accepted_only_with_the_same_file_and_range(): void
    {
        $manifest = $this->manifest();
        $this->record($manifest, 1, 1, 3);

        $this->assertSame([], $this->problems($manifest, 1, 1, 3));
        $this->assertStringContainsString('different range', implode("\n", $this->problems($manifest, 1, 1, 2)));

        $changed = $this->manifest(function (array $m) {
            $m['scenarios'][5]['topic'] = 'Something else?';

            return $m;
        });
        $this->assertStringContainsString('different manifest', implode("\n", $this->problems($changed, 1, 1, 3)));
    }

    public function test_waves_of_another_study_do_not_count(): void
    {
        $this->record($this->manifest(), 1, 1, 3)->update(['study' => 'other']);

        $this->assertSame([], $this->problems($this->manifest(), 1, 1, 3));
    }

    private function problems(Manifest $manifest, int $number, int $from, int $to): array
    {
        return (new WaveRules)->problems($manifest, $number, $from, $to);
    }

    private function manifest(?callable $change = null): Manifest
    {
        $data = json_decode(ManifestTest::fixture('probe-manifest.json'), true);

        return Manifest::fromJson(json_encode($change ? $change($data) : $data));
    }

    private function record(Manifest $manifest, int $number, int $from, int $to): StudyWave
    {
        return StudyWave::record($manifest, $number, $from, $to, null);
    }
}
