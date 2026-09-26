<?php

namespace App\Discussion\Values;

/**
 * One run's speaking shares: the study's two primary measures side by side.
 *
 * Turn share counts how often an agent got the floor, conversation share how
 * much text it contributed. They can diverge, and that divergence is the point
 * — so both live in one report rather than being computed apart.
 *
 * Length is counted in words, never in model tokens: the tokenizers of the
 * model families differ, which would bias the very comparison the study makes.
 */
final readonly class ShareReport
{
    /**
     * @param  list<SpeakerShare>  $speakers  ordered by seat, so a participant keeps
     *                                        the same colour across both charts
     * @param  float  $giniCeiling  the largest coefficient this group size can reach,
     *                              (n-1)/n — a bare coefficient is unreadable without it
     */
    public function __construct(
        public array $speakers,
        public int $spokenTurns,
        public int $totalWords,
        public float $turnGini,
        public float $wordGini,
        public float $giniCeiling,
    ) {}

    /** A report for a run that has not started; keeps six zeroes out of the caller. */
    public static function empty(): self
    {
        return new self(speakers: [], spokenTurns: 0, totalWords: 0, turnGini: 0.0, wordGini: 0.0, giniCeiling: 0.0);
    }

    public function isEmpty(): bool
    {
        return $this->spokenTurns === 0;
    }
}
