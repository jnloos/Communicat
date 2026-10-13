<?php

namespace App\Services\Transfer;

use Throwable;

/**
 * What a report says about a failure. A bundle that cannot be read, or an
 * entry of the wrong shape, explains itself; anything else may carry SQL or
 * paths, so the report gets a generic note and the exception goes to the log.
 */
final class FailureNote
{
    public static function for(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof InvalidBundle => $exception->getMessage(),
            $exception instanceof UnreadableEntry => __('transfer.notes.unreadable_entry'),
            default => self::logged($exception),
        };
    }

    private static function logged(Throwable $exception): string
    {
        report($exception);

        return __('transfer.notes.unexpected_error');
    }
}
