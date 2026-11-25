<?php

namespace Tests\Feature\Study\Planning;

use App\Services\Study\Planning\Manifest;
use App\Services\Study\Planning\Planner;
use App\Services\Study\Planning\RunPlan;
use Tests\TestCase;

/** "name_draw": "reuse": names are drawn with replacement, so a full name comes back across instances. */
class NameReuseTest extends TestCase
{
    /** @var list<RunPlan> */
    private array $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plan = (new Planner)->plan(PlannerBlockSeatingTest::blockManifest(), 1, 24);
    }

    public function test_the_probe_names_carry_24_scenarios_and_names_come_back_across_instances(): void
    {
        $instances = [];
        foreach ($this->plan as $run) {
            foreach ($run->personas as $persona) {
                $instances[$persona->name]["{$run->scenario}:{$run->block}"] = true;
            }
        }

        $this->assertGreaterThan(1, max(array_map('count', $instances)));
    }

    public function test_a_scenario_never_holds_two_people_of_one_name(): void
    {
        $byScenario = [];
        foreach ($this->plan as $run) {
            foreach ($run->personas as $place => $persona) {
                $byScenario[$run->scenario][$persona->name]["{$run->block}:{$place}"] = true;
            }
        }

        foreach ($byScenario as $scenario => $names) {
            $this->assertSame([1], array_values(array_unique(array_map('count', $names))), "scenario {$scenario}");
        }
    }

    public function test_a_cast_still_shares_no_first_and_no_last_name(): void
    {
        foreach ($this->plan as $run) {
            $people = array_values($run->personas);
            $this->assertCount(3, array_unique(array_map(fn ($persona) => $persona->firstName, $people)), $run->key);
            $this->assertCount(3, array_unique(array_map(fn ($persona) => $persona->lastName, $people)), $run->key);
        }
    }

    public function test_an_excluded_full_name_is_never_drawn(): void
    {
        $excluded = $this->plan[0]->personas['focus']->name;
        $data = json_decode(ManifestTest::fixture('probe-manifest.json'), true);
        $data['name_draw'] = 'reuse';
        $data['excluded_full_names'] = [$excluded];

        foreach ((new Planner)->plan(Manifest::fromJson(json_encode($data)), 1, 6) as $run) {
            foreach ($run->personas as $persona) {
                $this->assertNotSame($excluded, $persona->name, $run->key);
            }
        }
    }
}
