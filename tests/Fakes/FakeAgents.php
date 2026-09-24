<?php

namespace Tests\Fakes;

/**
 * The SDK's Agent::fake() takes a list indexed by call order and falls back to a
 * generated placeholder once the list runs dry. Our stages parse markers out of
 * the answer, so a placeholder means a parse failure on the second turn. These
 * helpers hand back a closure instead: the same answer for every call, however
 * many turns a test runs.
 */
class FakeAgents
{
    public static function always(string $agentClass, string $text): void
    {
        $agentClass::fake(fn () => $text);
    }

    /** @param  string[]  $texts  answer n for call n; the last one repeats. */
    public static function inOrder(string $agentClass, array $texts): void
    {
        $call = 0;

        $agentClass::fake(function () use ($texts, &$call) {
            $text = $texts[$call] ?? end($texts);
            $call++;

            return $text;
        });
    }

    public static function fails(string $agentClass, \Throwable $error): void
    {
        $agentClass::fake(fn () => throw $error);
    }
}
