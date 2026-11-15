<?php

namespace App\Study\Planning;

use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Study-only, removable: see app/Study/README.md.
 *
 * Every random choice of the planner (spec 2.2), from the master seed alone and
 * never from PHP's global RNG. Each draw names its own domain and parts, so
 * adding a draw cannot shift another: the same pattern as SeededShuffle, which
 * needs models and so cannot serve a pure function.
 */
final class Draws
{
    /** The range Project::booted() draws a seed from. */
    public const MAX_SEED = 2_000_000_000;

    public function __construct(private readonly int $masterSeed) {}

    public function bytes(string $domain, string|int ...$parts): string
    {
        return hash('sha256', implode("\x1F", [$domain, $this->masterSeed, ...$parts]), binary: true);
    }

    /** A run's project seed, 1..MAX_SEED. */
    public function seed(string $runKey): int
    {
        return 1 + hexdec(substr(bin2hex($this->bytes('seed', $runKey)), 0, 8)) % self::MAX_SEED;
    }

    /**
     * @template T
     *
     * @param  list<T>  $list
     * @return list<T>
     */
    public function shuffle(array $list, string $domain, string|int ...$parts): array
    {
        return (new Randomizer(new Xoshiro256StarStar($this->bytes($domain, ...$parts))))->shuffleArray($list);
    }
}
