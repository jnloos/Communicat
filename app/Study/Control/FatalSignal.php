<?php

namespace App\Study\Control;

/**
 * Reads from a turn's job_logs.error whether the error is global (a rejected key,
 * exhausted credit), so the breaker trips at once instead of after the streak. Only a
 * shortcut: a pattern that stops matching after an SDK update leaves the streak.
 */
class FatalSignal
{
    /** The signal name of the first pattern that matches, null for an ordinary error. */
    public function matches(string $error): ?string
    {
        foreach (config('study.breaker.fatal_patterns') as $signal => $pattern) {
            if (preg_match($pattern, $error) === 1) {
                return $signal;
            }
        }

        return null;
    }
}
