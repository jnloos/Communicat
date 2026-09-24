<?php

namespace App\Discussion\Support;

use App\Models\Expert;
use App\Models\Project;
use InvalidArgumentException;

/**
 * Resolves a tie without favouring anyone. Deterministic on purpose: the draw
 * comes from the project's seed and the turn index, so a rerun of the same run
 * makes the same choices — projects.seed exists for exactly this.
 *
 * No RNG state is touched (no mt_srand): a global seed would leak into whatever
 * else draws random numbers in the same process.
 */
class TieBreaker
{
    /** @param  list<Expert>  $candidates */
    public function pick(array $candidates, Project $project, int $turnIndex): Expert
    {
        if ($candidates === []) {
            throw new InvalidArgumentException('TieBreaker needs at least one candidate.');
        }

        if (count($candidates) === 1) {
            return $candidates[0];
        }

        // Sorted by id so the caller's array order cannot influence the outcome.
        usort($candidates, fn (Expert $a, Expert $b) => $a->id <=> $b->id);

        $draw = crc32($project->seed.':'.$turnIndex);

        return $candidates[$draw % count($candidates)];
    }
}
