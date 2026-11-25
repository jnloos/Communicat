<?php

namespace Tests\Feature\Study\Planning;

use App\Study\Planning\Manifest;
use App\Study\Planning\Planner;
use App\Study\Planning\Pot;
use App\Study\Planning\RunPlan;
use Tests\TestCase;

/** A manifest with "seating": "table": fixed seats, the description follows the seat (handout 26.11. §2). */
class PlannerSeatTableTest extends TestCase
{
    private Manifest $manifest;

    /** @var list<RunPlan> */
    private array $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manifest = self::tableManifest();
        $this->plan = (new Planner)->plan($this->manifest, 1, 6);
    }

    public static function tableManifest(): Manifest
    {
        $data = json_decode(ManifestTest::fixture('probe-manifest.json'), true);

        return Manifest::fromJson(json_encode(['seating' => 'table'] + $data));
    }

    public function test_every_seat_reads_its_own_description_focus_and_duo_alike(): void
    {
        foreach ($this->plan as $run) {
            $descriptions = $this->manifest->scenario($run->scenario)['descriptions'];

            foreach (['A', 'B', 'C'] as $index => $letter) {
                $persona = $run->seating()[$index];
                $this->assertSame($letter, $persona->letter, $run->key);
                $this->assertSame($descriptions[$letter], $persona->description, $run->key);
            }

            $this->assertLessThan($run->seatOf('duo2'), $run->seatOf('duo1'), $run->key);
        }
    }

    public function test_the_focus_keeps_its_seat_over_the_four_status_cells_of_a_block_and_pipeline(): void
    {
        $seats = [];
        foreach ($this->plan as $run) {
            $seats["{$run->scenario}:{$run->block}:{$run->pipeline}"][] = $run->seatOf('focus');
        }

        foreach ($seats as $cell => $four) {
            $this->assertCount(4, $four);
            $this->assertCount(1, array_unique($four), $cell);
        }
    }

    public function test_names_stay_per_instance_the_description_moves_with_the_seat(): void
    {
        $instances = [];
        foreach ($this->plan as $run) {
            $instances["{$run->scenario}:{$run->block}"][] = $run;
        }

        foreach ($instances as $instance => $runs) {
            foreach (['focus', 'duo1', 'duo2'] as $place) {
                $this->assertCount(1, array_unique(array_map(fn (RunPlan $run) => $run->personas[$place]->name, $runs)), $instance);
            }

            // Focus: 2 titles × 3 seats; each duo member: 2 titles × 2 seats.
            $this->assertCount(6, array_unique(array_map(fn (RunPlan $run) => $run->personas['focus']->uuid, $runs)), $instance);
            $this->assertCount(4, array_unique(array_map(fn (RunPlan $run) => $run->personas['duo1']->uuid, $runs)), $instance);
        }
    }

    public function test_a_both_halves_of_every_block_split_see_the_same_seats_per_scenario_and_pipeline(): void
    {
        $splits = [
            'gender focus' => fn (int $block) => Pot::focusOf($block)->gender(),
            'ethnicity focus' => fn (int $block) => Pot::focusOf($block)->ethnicity(),
            'gender duo' => fn (int $block) => Pot::duoOf($block)->gender(),
            'ethnicity duo' => fn (int $block) => Pot::duoOf($block)->ethnicity(),
        ];

        foreach ($splits as $name => $half) {
            $counts = [];
            foreach ($this->focusSeats() as [$run, $seat]) {
                $counts["{$run->scenario}:{$run->pipeline}"][$half($run->block)][$seat - 1] ??= 0;
                $counts["{$run->scenario}:{$run->pipeline}"][$half($run->block)][$seat - 1]++;
            }

            foreach ($counts as $cell => $halves) {
                $this->assertCount(2, $halves);
                [$first, $second] = array_map(fn (array $seats) => array_replace([0, 0, 0], $seats), array_values($halves));
                $this->assertSame($first, $second, "{$name} {$cell}");

                sort($first);
                $this->assertSame([2, 3, 3], $first, "{$name} {$cell}");
            }
        }
    }

    public function test_b_the_three_pipelines_of_a_cell_put_the_focus_on_three_seats(): void
    {
        $seats = [];
        foreach ($this->plan as $run) {
            $seats["{$run->scenario}:{$run->block}:{$run->focusStatus}{$run->duoStatus}"][] = $run->seatOf('focus');
        }

        foreach ($seats as $cell => $three) {
            $this->assertEqualsCanonicalizing([1, 2, 3], $three, $cell);
        }
    }

    public function test_c_every_three_scenarios_fill_each_seat_of_a_pipeline_16_times(): void
    {
        $counts = [];
        foreach ($this->focusSeats() as [$run, $seat]) {
            $cell = intdiv($run->scenario - 1, 3).":{$run->pipeline}";
            $counts[$cell][$seat] ??= 0;
            $counts[$cell][$seat]++;
        }

        $this->assertCount(2 * 3, $counts);
        foreach ($counts as $cell => $seats) {
            ksort($seats);
            $this->assertSame([1 => 16, 2 => 16, 3 => 16], $seats, $cell);
        }
    }

    /** @return list<array{0: RunPlan, 1: int}> one entry per scenario, block and pipeline (status cell hh) */
    private function focusSeats(): array
    {
        return array_values(array_map(
            fn (RunPlan $run) => [$run, $run->seatOf('focus')],
            array_filter($this->plan, fn (RunPlan $run) => $run->focusStatus.$run->duoStatus === 'hh'),
        ));
    }
}
