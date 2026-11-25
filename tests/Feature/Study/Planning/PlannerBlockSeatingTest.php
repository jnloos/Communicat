<?php

namespace Tests\Feature\Study\Planning;

use App\Services\Study\Planning\Manifest;
use App\Services\Study\Planning\Planner;
use App\Services\Study\Planning\RunPlan;
use Tests\TestCase;

/** A manifest with "seating": "block": the focus seat is drawn per scenario and block, the description follows the seat. */
class PlannerBlockSeatingTest extends TestCase
{
    private const SCENARIOS = 24;

    private Manifest $manifest;

    /** @var list<RunPlan> */
    private array $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manifest = self::blockManifest();
        $this->plan = (new Planner)->plan($this->manifest, 1, self::SCENARIOS);
    }

    /** The probe manifest seated by block and reusing names, its six scenarios repeated up to 24. */
    public static function blockManifest(?int $masterSeed = null): Manifest
    {
        $data = json_decode(ManifestTest::fixture('probe-manifest.json'), true);
        $data['seating'] = 'block';
        $data['name_draw'] = 'reuse';
        $data['master_seed'] = $masterSeed ?? $data['master_seed'];
        // Each repeat gets texts of its own, as distinct scenarios have: a persona's uuid hangs on them.
        $data['scenarios'] = array_map(function (int $id) use ($data) {
            $scenario = $data['scenarios'][($id - 1) % count($data['scenarios'])];
            $scenario['descriptions'] = array_map(fn (string $text) => "{$text} (scenario {$id})", $scenario['descriptions']);

            return ['id' => $id] + $scenario;
        }, range(1, self::SCENARIOS));

        return Manifest::fromJson(json_encode($data));
    }

    public function test_the_focus_keeps_its_seat_over_all_status_cells_and_pipelines_of_a_block(): void
    {
        $seats = [];
        foreach ($this->plan as $run) {
            $seats["{$run->scenario}:{$run->block}"][] = $run->seatOf('focus');
        }

        $this->assertCount(self::SCENARIOS * 16, $seats);
        foreach ($seats as $block => $twelve) {
            $this->assertCount(4 * 3, $twelve);
            $this->assertCount(1, array_unique($twelve), $block);
        }
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

    public function test_the_master_seed_decides_the_seats(): void
    {
        $seatsOf = fn (array $plan) => array_map(fn (RunPlan $run) => $run->seats, $plan);

        $this->assertSame($seatsOf($this->plan), $seatsOf((new Planner)->plan(self::blockManifest(), 1, self::SCENARIOS)));
        $this->assertNotSame($seatsOf($this->plan), $seatsOf((new Planner)->plan(self::blockManifest(4712), 1, self::SCENARIOS)));
    }

    /** A loose bound against a broken draw such as "always seat 1", not a balance the planner promises. */
    public function test_every_seat_gets_between_25_and_42_percent_of_a_pipelines_runs(): void
    {
        $counts = [];
        foreach ($this->plan as $run) {
            $counts[$run->pipeline][$run->seatOf('focus')] = ($counts[$run->pipeline][$run->seatOf('focus')] ?? 0) + 1;
        }

        foreach ($counts as $pipeline => $seats) {
            $this->assertSame(self::SCENARIOS * 16 * 4, array_sum($seats), $pipeline);
            $this->assertCount(3, $seats, $pipeline);
            foreach ($seats as $seat => $runs) {
                $share = $runs / array_sum($seats);
                $this->assertTrue($share >= 0.25 && $share <= 0.42, "{$pipeline} seat {$seat}: {$share}");
            }
        }
    }
}
