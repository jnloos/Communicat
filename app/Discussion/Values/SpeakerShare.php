<?php

namespace App\Discussion\Values;

use Illuminate\Contracts\Support\Arrayable;

/** What one participant contributed to a run, in both of the study's units. */
/** @implements Arrayable<string, int|float|string|null> */
final readonly class SpeakerShare implements Arrayable
{
    /** The keys of toArray(), for a table header that has to stand without a row. */
    public const COLUMNS = ['expert_id', 'name', 'seat', 'turns', 'turn_share', 'words', 'word_share', 'words_per_turn'];

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

    /**
     * Shares rounded to six places, enough for any analysis and short enough
     * to read.
     *
     * @return array{expert_id: int, name: string, seat: ?int, turns: int, turn_share: float, words: int, word_share: float, words_per_turn: float}
     */
    public function toArray(): array
    {
        return [
            'expert_id' => $this->expertId,
            'name' => $this->name,
            'seat' => $this->seat,
            'turns' => $this->turns,
            'turn_share' => round($this->turnShare, 6),
            'words' => $this->words,
            'word_share' => round($this->wordShare, 6),
            'words_per_turn' => round($this->wordsPerTurn(), 6),
        ];
    }
}
