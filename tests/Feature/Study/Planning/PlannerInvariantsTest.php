<?php

namespace Tests\Feature\Study\Planning;

use App\Study\Planning\Manifest;
use App\Study\Planning\PersonaPlan;
use App\Study\Planning\Planner;
use App\Study\Planning\Pot;
use App\Study\Planning\RunPlan;
use Tests\TestCase;

/** The invariants of spec section 2.5, each on the probe manifest. */
class PlannerInvariantsTest extends TestCase
{
    private Manifest $manifest;

    /** @var list<RunPlan> */
    private array $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manifest = Manifest::fromJson(ManifestTest::fixture('probe-manifest.json'));
        $this->plan = (new Planner)->plan($this->manifest, 1, 6);
    }

    public function test_a_persona_of_another_study_is_another_record(): void
    {
        $other = json_decode(ManifestTest::fixture('probe-manifest.json'), true);
        $other['study'] = 'other';
        $plan = (new Planner)->plan(Manifest::fromJson(json_encode($other)), 1, 1);

        $this->assertSame($this->plan[0]->personas['focus']->name, $plan[0]->personas['focus']->name);
        $this->assertNotSame($this->plan[0]->personas['focus']->uuid, $plan[0]->personas['focus']->uuid);
    }

    public function test_1_every_scenario_has_16_instances_of_4_status_cells_per_pipeline(): void
    {
        $this->assertCount(6 * 16 * 4 * 3, $this->plan);
        $this->assertCount(count($this->plan), array_unique(array_map(fn (RunPlan $run) => $run->key, $this->plan)));
        $this->assertCount(count($this->plan), array_unique(array_map(fn (RunPlan $run) => $run->uuid, $this->plan)));
        $this->assertSame('probe:s03:b07:hl:ScorePipeline', $this->runAt(3, 7, 'h', 'l', 'ScorePipeline')->key);
    }

    public function test_2_names_and_descriptions_are_shared_within_an_instance_titles_follow_status(): void
    {
        foreach ($this->instances() as $runs) {
            foreach (['focus', 'duo1', 'duo2'] as $place) {
                $this->assertCount(1, array_unique(array_map(fn (RunPlan $run) => $run->personas[$place]->name, $runs)));
                $this->assertCount(1, array_unique(array_map(fn (RunPlan $run) => $run->personas[$place]->description, $runs)));
            }

            foreach ($runs as $run) {
                $titles = $this->manifest->scenario($run->scenario)['titles'];
                $this->assertSame($titles[$run->focusStatus === 'h' ? 'high' : 'low'], $run->personas['focus']->attributes['Role']);
                $this->assertSame($titles[$run->duoStatus === 'h' ? 'high' : 'low'], $run->personas['duo1']->attributes['Role']);
                $this->assertSame($titles[$run->duoStatus === 'h' ? 'high' : 'low'], $run->personas['duo2']->attributes['Role']);
            }

            // Without a seat table a persona is the same record in the 2 status cells × 3 pipelines it belongs to.
            $this->assertCount(2, array_unique(array_map(fn (RunPlan $run) => $run->personas['focus']->uuid, $runs)));
        }

        $seeds = array_map(fn (RunPlan $run) => $run->seed, $this->plan);
        $this->assertCount(count($seeds), array_unique($seeds));
    }

    public function test_3_the_focus_reads_description_a_the_duo_b_and_c(): void
    {
        foreach ($this->plan as $run) {
            $descriptions = $this->manifest->scenario($run->scenario)['descriptions'];
            $this->assertSame($descriptions['A'], $run->personas['focus']->description);
            $this->assertSame($descriptions['B'], $run->personas['duo1']->description);
            $this->assertSame($descriptions['C'], $run->personas['duo2']->description);
        }
    }

    public function test_exactly_the_focus_place_carries_the_focus_tag_with_and_without_a_seat_table(): void
    {
        foreach ([$this->plan, (new Planner)->plan(PlannerSeatTableTest::tableManifest(), 1, 1)] as $plan) {
            $byUuid = [];
            foreach ($plan as $run) {
                foreach ($run->personas as $place => $persona) {
                    $this->assertSame($place === 'focus' ? 'true' : 'false', $persona->tags()['focus'], $run->key);
                    $byUuid[$persona->uuid][$persona->tags()['focus']] = true;
                }
            }

            // A record is the focus or not, wherever it sits.
            $this->assertSame([1], array_values(array_unique(array_map('count', $byUuid))));
        }
    }

    public function test_4_no_full_name_in_two_instances_and_no_shared_first_or_last_name_within_one(): void
    {
        $instanceOf = [];

        foreach ($this->instances() as $instance => $runs) {
            $people = array_values($runs[0]->personas);
            $this->assertCount(3, array_unique(array_map(fn (PersonaPlan $persona) => $persona->firstName, $people)), $instance);
            $this->assertCount(3, array_unique(array_map(fn (PersonaPlan $persona) => $persona->lastName, $people)), $instance);

            foreach ($people as $persona) {
                $this->assertArrayNotHasKey($persona->name, $instanceOf, "{$persona->name} in {$instance} and ".($instanceOf[$persona->name] ?? ''));
                $this->assertNotContains($persona->name, $this->manifest->excludedFullNames());
            }
            foreach ($people as $persona) {
                $instanceOf[$persona->name] = $instance;
            }
        }
    }

    public function test_5_names_per_subgroup_are_balanced_per_scenario_and_pot(): void
    {
        $perSubgroup = [];
        $focusSubgroup = [];

        foreach ($this->instances() as $runs) {
            $run = $runs[0];
            foreach ($run->personas as $place => $persona) {
                $pot = $place === 'focus' ? Pot::focusOf($run->block) : Pot::duoOf($run->block);
                $perSubgroup[$run->scenario][$pot->name][$persona->subgroup] = ($perSubgroup[$run->scenario][$pot->name][$persona->subgroup] ?? 0) + 1;

                if ($place === 'focus') {
                    $focusSubgroup[intdiv($run->scenario - 1, 3)][$pot->name][$persona->subgroup] = ($focusSubgroup[intdiv($run->scenario - 1, 3)][$pot->name][$persona->subgroup] ?? 0) + 1;
                }
            }
        }

        foreach ($perSubgroup as $pots) {
            $this->assertCount(4, $pots);
            foreach ($pots as $counts) {
                $this->assertSame([4, 4, 4], array_values($counts));
            }
        }

        // Three scenarios, four focus places per pot: 12 = each of the 3 subgroups 4 times.
        foreach ($focusSubgroup as $pots) {
            foreach ($pots as $counts) {
                $this->assertSame([4, 4, 4], array_values($counts));
            }
        }
    }

    public function test_6_a_shorter_plan_is_a_prefix_and_an_appended_scenario_changes_no_earlier_row(): void
    {
        $planner = new Planner;
        $rows = fn (array $plan) => array_map(fn (RunPlan $run) => $run->toArray(), $plan);

        $this->assertSame($rows(array_slice($this->plan, 0, 2 * 192)), $rows($planner->plan($this->manifest, 1, 2)));
        $this->assertSame($rows(array_slice($this->plan, 3 * 192, 192)), $rows($planner->plan($this->manifest, 4, 4)));

        $data = json_decode(ManifestTest::fixture('probe-manifest.json'), true);
        $data['scenarios'][] = ['id' => 7] + array_slice($data['scenarios'][0], 1);
        $data['scenarios'][6]['topic'] = 'Should the ferry run at night?';
        $sameCore = Manifest::fromJson(json_encode($data));

        $this->assertSame($rows($this->plan), $rows($planner->plan($sameCore, 1, 6)));
    }

    public function test_7_no_global_random_state_is_read(): void
    {
        $planner = new Planner;
        mt_srand(1);
        $first = array_map(fn (RunPlan $run) => $run->toArray(), $planner->plan($this->manifest, 1, 1));
        mt_srand(2);
        $second = array_map(fn (RunPlan $run) => $run->toArray(), $planner->plan($this->manifest, 1, 1));

        $this->assertSame($first, $second);
    }

    public function test_8_seeds_are_in_range_and_seats_are_permutations(): void
    {
        foreach ($this->plan as $run) {
            $this->assertGreaterThanOrEqual(1, $run->seed);
            $this->assertLessThanOrEqual(2_000_000_000, $run->seed);

            $seats = [$run->seatOf('focus'), $run->seatOf('duo1'), $run->seatOf('duo2')];
            sort($seats);
            $this->assertSame([1, 2, 3], $seats);
            $this->assertSame($run->personas['focus'], $run->seating()[$run->seatOf('focus') - 1]);
        }
    }

    /** @return array<string, list<RunPlan>> "s03:b07" => its runs */
    private function instances(): array
    {
        $instances = [];
        foreach ($this->plan as $run) {
            $instances[sprintf('s%02d:b%02d', $run->scenario, $run->block)][] = $run;
        }

        return $instances;
    }

    private function runAt(int $scenario, int $block, string $focus, string $duo, string $pipeline): RunPlan
    {
        foreach ($this->plan as $run) {
            if ([$run->scenario, $run->block, $run->focusStatus, $run->duoStatus, $run->pipeline] === [$scenario, $block, $focus, $duo, $pipeline]) {
                return $run;
            }
        }

        $this->fail('No such run.');
    }
}
