<?php

namespace App\Discussion\Support;

use App\Models\Expert;
use App\Models\Project;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Puts experts in a random order that a rerun reproduces: the draw comes from
 * the project's seed and the turn index, as in TieBreaker.
 *
 * The engine is a local instance, never mt_srand(): a global seed would leak
 * into whatever else draws random numbers in the same process.
 */
class SeededShuffle
{
    /**
     * @param  list<Expert>  $experts
     * @return list<Expert>
     */
    public function shuffle(array $experts, Project $project, int $turnIndex): array
    {
        // Sorted by id first so the caller's array order cannot influence the outcome.
        usort($experts, fn (Expert $a, Expert $b) => $a->id <=> $b->id);

        $engine = new Xoshiro256StarStar(hash('sha256', $project->seed.':'.$turnIndex, binary: true));

        return (new Randomizer($engine))->shuffleArray($experts);
    }
}
