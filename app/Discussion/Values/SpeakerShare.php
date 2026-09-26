<?php

namespace App\Discussion\Values;

/** What one participant contributed to a run, in both of the study's units. */
final readonly class SpeakerShare
{
    public function __construct(
        public int $expertId,
        public string $name,
        public ?int $seat,
        public int $turns,
        public int $words,
        /** Share of all spoken turns, 0..1. */
        public float $turnShare,
        /** Share of all contributed words, 0..1. */
        public float $wordShare,
    ) {}

    /**
     * Words per turn. The ratio of word share to turn share is what tells the
     * two units apart (MacLaren2020): a speaker who takes the floor rarely but
     * writes at length scores above the group average here.
     */
    public function wordsPerTurn(): float
    {
        return $this->turns === 0 ? 0.0 : $this->words / $this->turns;
    }
}
