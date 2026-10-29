<?php

namespace Tests\Fakes;

use Closure;
use Illuminate\Support\Sleep;
use Laravel\Ai\Classification;
use Laravel\Ai\Gateway\FakeClassificationGateway;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Laravel\Ai\Responses\Data\ScoreAnswer;
use Throwable;

/**
 * Jev without TypeSafe: Classification::fake() answers every request, so no test
 * reaches the real API. phpunit.xml empties TYPESAFE_API_KEY (an unfaked call
 * would otherwise be a real, billed one) and the Jev client refuses to run
 * without a key, so every helper here also sets a dummy one -- and fakes sleeping,
 * so a retry's backoff costs no time (Sleep::assertSlept() tells what it would have been).
 *
 * Every request the fake answers is kept in prompts(). Assert on that, outside
 * the fake's closure: an exception thrown inside a closure the SDK calls is
 * swallowed by the code that handles Jev's failures, so an assertion there
 * would pass without ever failing.
 */
class FakeJev
{
    /** @var list<ClassificationPrompt> */
    private static array $prompts = [];

    /** @return list<ClassificationPrompt> every request since the last helper armed the fake, in order */
    public static function prompts(): array
    {
        return self::$prompts;
    }

    /** The only request, for a test that expects exactly one. */
    public static function prompt(): ClassificationPrompt
    {
        self::assertSingle();

        return self::$prompts[0];
    }

    /** Random but shape-valid answers to every question, as the SDK's fake generates them. */
    public static function randomly(): FakeClassificationGateway
    {
        return self::answering(fn () => []);
    }

    /**
     * Every request answered by $answers(prompt): question key => answer, a
     * question left out gets a generated one.
     *
     * @param  Closure(ClassificationPrompt): array<string, mixed>  $answers
     */
    public static function answering(Closure $answers): FakeClassificationGateway
    {
        self::arm();

        return Classification::fake(function (ClassificationPrompt $prompt) use ($answers) {
            self::$prompts[] = $prompt;

            return $answers($prompt);
        });
    }

    /**
     * Every `speaker` question answered with this distribution (option => probability).
     *
     * @param  array<string, float>  $probabilities
     */
    public static function choosing(array $probabilities, ?string $choice = null): FakeClassificationGateway
    {
        return self::answering(fn () => ['speaker' => new ChoiceAnswer($choice ?? (string) array_search(max($probabilities), $probabilities), $probabilities, 0.9)]);
    }

    /**
     * Every `urgency` question answered from the expert named in the request
     * state: $levels[token] is that agent's five level probabilities. An agent
     * without an entry gets the lowest level.
     *
     * The expert is found by the line the persona partial writes ("You are Name
     * (your token: E1).", partials/persona), so rewording that line breaks every
     * test that uses this helper -- on purpose: Jev must be shown whose view it is.
     *
     * @param  array<string, list<float>>  $levels  token => probabilities of level 1..5
     */
    public static function urgencies(array $levels): FakeClassificationGateway
    {
        return self::answering(function (ClassificationPrompt $prompt) use ($levels) {
            foreach ($levels as $token => $probabilities) {
                if (str_contains((string) $prompt->state, "(your token: {$token})")) {
                    return ['urgency' => new ScoreAnswer(array_sum(array_map(fn ($p, $i) => $p * $i, $probabilities, array_keys($probabilities))), $probabilities, [], 0.8)];
                }
            }

            return ['urgency' => new ScoreAnswer(0.0, [1.0, 0.0, 0.0, 0.0, 0.0], [], 0.8)];
        });
    }

    public static function fails(Throwable $error): FakeClassificationGateway
    {
        return self::answering(fn () => throw $error);
    }

    /**
     * Request n gets step n, the last step repeats: a Throwable is thrown, an
     * array is the answer (question key => answer).
     *
     * @param  list<Throwable|array<string, mixed>>  $steps
     */
    public static function steps(array $steps): FakeClassificationGateway
    {
        $call = 0;

        return self::answering(function () use ($steps, &$call) {
            $step = $steps[min($call++, count($steps) - 1)];

            return $step instanceof Throwable ? throw $step : $step;
        });
    }

    public static function key(): void
    {
        config(['ai.providers.typesafe.key' => 'test-key']);
    }

    /** Dummy key, no real sleeping, nothing recorded yet. */
    private static function arm(): void
    {
        self::$prompts = [];
        self::key();
        Sleep::fake();
    }

    private static function assertSingle(): void
    {
        if (count(self::$prompts) !== 1) {
            throw new \LogicException(sprintf('Expected exactly one Jev request, saw %d.', count(self::$prompts)));
        }
    }
}
