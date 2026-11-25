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

    /** Without a seat table each role reads its own description. */
    private const LETTERS_BY_ROLE = ['focus' => 'A', 'duo1' => 'B', 'duo2' => 'C'];

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
                    foreach ($manifest->pipelines() as $index => $pipeline) {
                        $key = RunPlan::keyOf($manifest->study(), $scenario, $block, $statuses, $pipeline);
                        // A seat table fixes the seats, and the description follows the seat; otherwise
                        // seats are drawn per run, also between the pipelines of an instance, and never balanced.
                        $seats = $manifest->seatsByTable() ? SeatTable::seats($scenario, $block, $index) : $draws->shuffle(NameDraw::PLACES, 'seats', $key);
                        $letters = $manifest->seatsByTable() ? SeatTable::letters($seats) : self::LETTERS_BY_ROLE;
                        $personas = $this->cast($manifest, $names, $scenario, $block, $statuses, $letters);

                        $runs[] = $this->run($manifest, $draws, $key, $scenario, $block, $statuses, $pipeline, $seats, $personas);
                    }
                }
            }
        }

        return $runs;
    }

    /**
     * @param  list<'focus'|'duo1'|'duo2'>  $seats
     * @param  array{focus: PersonaPlan, duo1: PersonaPlan, duo2: PersonaPlan}  $personas
     */
    private function run(Manifest $manifest, Draws $draws, string $key, int $scenario, int $block, string $statuses, string $pipeline, array $seats, array $personas): RunPlan
    {
        return new RunPlan(
            study: $manifest->study(),
            scenario: $scenario,
            block: $block,
            focusStatus: $statuses[0],
            duoStatus: $statuses[1],
            pipeline: $pipeline,
            seed: $draws->seed($key),
            seats: $seats,
            personas: $personas,
            topic: $manifest->scenario($scenario)['topic'],
            model: $manifest->model(),
            turnBudget: $manifest->turnBudget(),
        );
    }

    /**
     * @param  array{focus: string, duo1: string, duo2: string}  $letters  place => description letter
     * @return array{focus: PersonaPlan, duo1: PersonaPlan, duo2: PersonaPlan}
     */
    private function cast(Manifest $manifest, NameDraw $names, int $scenario, int $block, string $statuses, array $letters): array
    {
        $content = $manifest->scenario($scenario);
        $cast = [];

        foreach (NameDraw::PLACES as $place) {
            $letter = $letters[$place];
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
