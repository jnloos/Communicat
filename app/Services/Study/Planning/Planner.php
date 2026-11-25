<?php

namespace App\Services\Study\Planning;

/**
 * Thesis study, see docs/study.md.
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

    /** Drawn seats per run: each role reads its own description. */
    private const LETTERS_BY_ROLE = ['focus' => 'A', 'duo1' => 'B', 'duo2' => 'C'];

    /** Seated by block: the description follows the seat, 1 → A, 2 → B, 3 → C. */
    private const LETTERS_BY_SEAT = ['A', 'B', 'C'];

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
                    foreach ($manifest->pipelines() as $pipeline) {
                        $key = RunPlan::keyOf($manifest->study(), $scenario, $block, $statuses, $pipeline);
                        // Seated by block, the focus seat is drawn once per scenario and block and the description
                        // follows the seat; otherwise seats are drawn per run. Never balanced.
                        $seats = $manifest->seatsByBlock()
                            ? $this->blockSeats($draws, $manifest->study(), $scenario, $block)
                            : $draws->shuffle(NameDraw::PLACES, 'seats', $key);
                        $letters = $manifest->seatsByBlock() ? array_combine($seats, self::LETTERS_BY_SEAT) : self::LETTERS_BY_ROLE;
                        $personas = $this->cast($manifest, $names, $scenario, $block, $statuses, $letters);

                        $runs[] = $this->run($manifest, $draws, $key, $scenario, $block, $statuses, $pipeline, $seats, $personas);
                    }
                }
            }
        }

        return $runs;
    }

    /**
     * The focus seat, drawn with neither status cell nor pipeline in the key, so the focus keeps it
     * over all twelve runs of a block and the seat cancels out of the status and the pipeline
     * comparison alike; the duo takes the other seats, duo1 the lower one.
     *
     * @return list<'focus'|'duo1'|'duo2'> places in seat order
     */
    private function blockSeats(Draws $draws, string $study, int $scenario, int $block): array
    {
        $seats = ['duo1', 'duo2'];
        $focus = $draws->shuffle([0, 1, 2], 'focus-seat', $study, $scenario, $block)[0];
        array_splice($seats, $focus, 0, 'focus');

        return $seats;
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
                focus: $place === 'focus',
            );
        }

        return $cast;
    }
}
