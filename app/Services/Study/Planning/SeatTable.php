<?php

namespace App\Services\Study\Planning;

/**
 * Thesis study, see docs/study.md.
 *
 * The fixed seating of a manifest with "seating": "table" (handout 26.11. §2):
 * the focus persona's seat follows block, pipeline and scenario, so that per
 * pipeline and scenario both halves of every block split (gender or ethnicity
 * of focus or duo) see the same seats, the three pipelines of a cell put the
 * focus on three different seats, and every three scenarios fill each seat of
 * a pipeline equally. The knowledge text belongs to the seat, not to the role.
 */
final class SeatTable
{
    /** Offset per block, index b - 1. */
    private const OFFSETS = [1, 2, 0, 1, 0, 0, 2, 1, 0, 1, 2, 0, 1, 2, 1, 0];

    /** The description each seat reads: seat 1 → A, 2 → B, 3 → C. */
    private const LETTERS = ['A', 'B', 'C'];

    /**
     * Seat of the focus = (T[b] + p + (s - 1)) mod 3 + 1; the duo takes the other
     * two seats, duo1 the lower one.
     *
     * @param  int  $pipeline  index in the manifest's pipeline list (0, 1, 2)
     * @return list<'focus'|'duo1'|'duo2'> places in seat order
     */
    public static function seats(int $scenario, int $block, int $pipeline): array
    {
        $focus = (self::OFFSETS[$block - 1] + $pipeline + $scenario - 1) % 3;
        $duo = ['duo1', 'duo2'];

        return array_map(function (int $seat) use ($focus, &$duo) {
            return $seat === $focus ? 'focus' : array_shift($duo);
        }, [0, 1, 2]);
    }

    /**
     * @param  list<'focus'|'duo1'|'duo2'>  $seats
     * @return array{focus: string, duo1: string, duo2: string} place => description letter
     */
    public static function letters(array $seats): array
    {
        return array_combine($seats, self::LETTERS);
    }
}
