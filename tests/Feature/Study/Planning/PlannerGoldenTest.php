<?php

namespace Tests\Feature\Study\Planning;

use App\Study\Planning\Manifest;
use App\Study\Planning\Planner;
use App\Study\Planning\RunPlan;
use Tests\TestCase;

/**
 * Pins the planner's output for the tiny manifest byte for byte, and with it
 * Randomizer and Xoshiro256StarStar: a PHP upgrade that changed a draw would
 * reseat or rename a study silently. Regenerate plan-golden.json only for a
 * deliberate change of the algorithm.
 */
class PlannerGoldenTest extends TestCase
{
    public function test_the_plan_of_the_tiny_manifest_is_pinned(): void
    {
        $this->assertSame(file_get_contents(__DIR__.'/../fixtures/plan-golden.json'), self::render());
    }

    public static function render(): string
    {
        $plan = (new Planner)->plan(Manifest::fromJson(ManifestTest::fixture('tiny-manifest.json')), 1, 1);

        return json_encode(array_map(fn (RunPlan $run) => $run->toArray(), $plan), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
    }
}
