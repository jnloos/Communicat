<?php

namespace App\Discussion\Jev;

use App\Discussion\AbortsRun;
use RuntimeException;

/**
 * A Jev pipeline without a TypeSafe key. A configuration error, not a model
 * answer: no turn would ever get a real Jev decision, and falling back to the
 * tie breaker would fill a whole cell with random speakers that look like data.
 * It is therefore thrown, not caught by the selectors' fallback, and it ends the
 * run (TurnRunner checks for it before a run's first turn, so a missing key costs
 * neither team-model calls nor an entry in the failure rate).
 */
final class JevNotConfigured extends RuntimeException implements AbortsRun
{
    public static function missingKey(): self
    {
        return new self('TYPESAFE_API_KEY is not set; a Jev pipeline cannot decide without it.');
    }
}
