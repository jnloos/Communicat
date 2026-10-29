<?php

namespace App\Discussion\Values;

use Laravel\Ai\Responses\Data\Answer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;

/**
 * What Jev's answer to a choice question says, read the way the study needs it:
 * a probability for every option that was offered, and the options that share
 * the top. The leaders are a list because Jev's probabilities can tie, and a
 * tie belongs to the TieBreaker, never to whichever option the API happened to
 * name in `choice`.
 *
 * `choice` is not read when the probabilities are there: it is the argmax of
 * the same distribution, and reading the distribution is what makes a tie
 * visible. It is the fallback only when the answer carries no probabilities.
 */
final readonly class JevChoice
{
    /**
     * @param  array<string, float>  $probabilities  option => probability, every offered option (0 when Jev named none); empty when the answer carried none
     * @param  list<string>  $leaders  the options at the top, at least one
     */
    private function __construct(
        public array $probabilities,
        public array $leaders,
        public ?float $confidence,
    ) {}

    /**
     * Null when the answer cannot decide anything: not a choice answer, a
     * probability that is not a non-negative number, a distribution without
     * mass on any offered option, or a named choice that was never offered.
     *
     * @param  list<string>  $options  the options that were offered
     */
    public static function read(?Answer $answer, array $options): ?self
    {
        if (! $answer instanceof ChoiceAnswer || $options === []) {
            return null;
        }

        $given = array_intersect_key($answer->probabilities, array_flip($options));

        if ($given === []) {
            return in_array($answer->choice, $options, true)
                ? new self([], [$answer->choice], self::confidence($answer))
                : null;
        }

        $probabilities = [];

        foreach ($options as $option) {
            $probability = $given[$option] ?? 0.0;

            if (! is_numeric($probability) || ! is_finite((float) $probability) || (float) $probability < 0) {
                return null;
            }

            $probabilities[$option] = round((float) $probability, JevReply::PRECISION);
        }

        $top = max($probabilities);

        if ($top <= 0) {
            return null;
        }

        return new self(
            $probabilities,
            array_keys(array_filter($probabilities, fn (float $probability) => $probability === $top)),
            self::confidence($answer),
        );
    }

    private static function confidence(ChoiceAnswer $answer): ?float
    {
        return $answer->confidence !== null && is_finite($answer->confidence) ? $answer->confidence : null;
    }
}
