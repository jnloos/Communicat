<?php

namespace Tests\Fakes;

/**
 * The SDK's Agent::fake() takes a list indexed by call order and falls back to a
 * generated answer once the list runs dry, so a test that runs more turns than
 * it listed gets something it never wrote. These helpers hand back a closure
 * instead: the same answer for every call, however many turns a test runs.
 *
 * An array answer becomes a structured response (FakeTextGateway marshals it
 * into a StructuredTextResponse); a string stays plain text.
 */
class FakeAgents
{
    public static function always(string $agentClass, array|string $answer): void
    {
        $agentClass::fake(fn () => $answer);
    }

    /** @param  list<array<string, mixed>|string>  $answers  answer n for call n; the last one repeats. */
    public static function inOrder(string $agentClass, array $answers): void
    {
        $call = 0;

        $agentClass::fake(function () use ($answers, &$call) {
            $answer = $answers[$call] ?? end($answers);
            $call++;

            return $answer;
        });
    }

    public static function fails(string $agentClass, \Throwable $error): void
    {
        $agentClass::fake(fn () => throw $error);
    }
}
