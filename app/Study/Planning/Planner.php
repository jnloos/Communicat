<?php

namespace App\Study\Planning;

/**
 * Study-only, removable: see app/Study/README.md.
 *
 * The pure function at the heart of the planning (spec 2): run key → cast. It
 * reads the manifest and nothing else -- no database, no global RNG -- so the
 * same manifest always yields the same runs, and study:check can recompute
 * them at any time to compare with what was written.
 */
final class Planner
{
    /** Focus status, duo status, in their fixed order. */
    public const STATUS_CELLS = ['hh', 'hl', 'lh', 'll'];

    /**
     * Scenario after scenario, then block, status cell and pipeline.
     *
     * @return list<RunPlan>
     */
    public function plan(Manifest $manifest, int $from, int $to): array
    {
        $names = NameDraw::through($manifest, $to);
        $draws = new Draws($manifest->masterSeed());
        $runs = [];

        for ($scenario = $from; $scenario <= $to; $scenario++) {
            for ($block = 1; $block <= Pot::BLOCKS; $block++) {
                foreach (self::STATUS_CELLS as $statuses) {
                    $personas = $this->cast($manifest, $names, $scenario, $block, $statuses);

                    foreach ($manifest->pipelines() as $pipeline) {
                        $runs[] = $this->run($manifest, $draws, $scenario, $block, $statuses, $pipeline, $personas);
                    }
                }
            }
        }

        return $runs;
    }

    /** @param  array{focus: PersonaPlan, duo1: PersonaPlan, duo2: PersonaPlan}  $personas */
    private function run(Manifest $manifest, Draws $draws, int $scenario, int $block, string $statuses, string $pipeline, array $personas): RunPlan
    {
        $key = RunPlan::keyOf($manifest->study(), $scenario, $block, $statuses, $pipeline);

        return new RunPlan(
            study: $manifest->study(),
            scenario: $scenario,
            block: $block,
            focusStatus: $statuses[0],
            duoStatus: $statuses[1],
            pipeline: $pipeline,
            seed: $draws->seed($key),
            // Drawn per run, also between the pipelines of an instance, and never balanced.
            seats: $draws->shuffle(NameDraw::PLACES, 'seats', $key),
            personas: $personas,
            topic: $manifest->scenario($scenario)['topic'],
            model: $manifest->model(),
            turnBudget: $manifest->turnBudget(),
        );
    }

    /** @return array{focus: PersonaPlan, duo1: PersonaPlan, duo2: PersonaPlan} */
    private function cast(Manifest $manifest, NameDraw $names, int $scenario, int $block, string $statuses): array
    {
        $content = $manifest->scenario($scenario);
        $letters = ['focus' => 'A', 'duo1' => 'B', 'duo2' => 'C'];
        $cast = [];

        foreach ($letters as $place => $letter) {
            $status = $place === 'focus' ? $statuses[0] : $statuses[1];
            $drawn = $names->at($scenario, $block, $place);

            $cast[$place] = new PersonaPlan(
                drawn: $drawn,
                attributes: [
                    'Role' => $content['titles'][$status === 'h' ? 'high' : 'low'],
                    'Gender' => $manifest->genderValue($drawn->gender),
                    'Origin' => $manifest->country($drawn->subgroup),
                ],
                letter: $letter,
                description: $content['descriptions'][$letter],
                study: $manifest->study(),
            );
        }

        return $cast;
    }
}
