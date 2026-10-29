<?php

namespace App\Discussion\Values;

use Laravel\Ai\Responses\Data\Answer;
use Laravel\Ai\Responses\Data\ScoreAnswer;

/**
 * Jev's answer to one score question as a single number on the scale of the
 * agents' own bids: the expected level over the level probabilities, 1 for the
 * lowest level up to the number of levels for the highest.
 *
 * The SDK's `score` field is the same expectation, but counted from 0 and
 * dependent on how the API numbers its levels; the probabilities are read by
 * position instead (lowest level first), so the result does not depend on that
 * numbering. `score` is only used when the answer carries no probabilities.
 *
 * An expectation over ordinal levels treats them as equally spaced, i.e. the
 * five levels as an interval scale. That is an assumption of this reading, not
 * a property of Jev's levels.
 */
final readonly class JevScore
{
    private function __construct(
        public float $value,
        public ?float $confidence,
    ) {}

    /** Null when the answer is not a score answer or its probabilities do not fit the scale. */
    public static function read(?Answer $answer, int $levels): ?self
    {
        if (! $answer instanceof ScoreAnswer) {
            return null;
        }

        $value = $answer->probabilities === []
            ? self::fromScore($answer->score, $levels)
            : self::fromProbabilities($answer->probabilities, $levels);

        if ($value === null) {
            return null;
        }

        $confidence = $answer->confidence !== null && is_finite($answer->confidence) ? $answer->confidence : null;

        return new self(round($value, JevReply::PRECISION), $confidence);
    }

    /** @param  array<int|string, mixed>  $probabilities */
    private static function fromProbabilities(array $probabilities, int $levels): ?float
    {
        ksort($probabilities);
        $probabilities = array_values($probabilities);

        if (count($probabilities) !== $levels) {
            return null;
        }

        foreach ($probabilities as $probability) {
            if (! is_numeric($probability) || ! is_finite((float) $probability) || (float) $probability < 0) {
                return null;
            }
        }

        $total = array_sum(array_map('floatval', $probabilities));

        if ($total <= 0) {
            return null;
        }

        $expected = 0.0;

        foreach ($probabilities as $position => $probability) {
            $expected += ($position + 1) * (float) $probability;
        }

        return $expected / $total;
    }

    private static function fromScore(float $score, int $levels): ?float
    {
        $level = $score + 1;

        return is_finite($score) && $level >= 1 && $level <= $levels ? $level : null;
    }
}
