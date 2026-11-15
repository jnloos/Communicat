<?php

namespace App\Discussion;

use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Laravel\Ai\Exceptions\FailoverableException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Throwable;

/**
 * The one rule for an error of the infrastructure rather than of the model: the
 * provider or gateway unreachable, rate limited or overloaded, a 5xx, a broadcast
 * that found no Reverb, a locked SQLite file. Such a turn says nothing about the
 * mechanism under study, so it is recorded as interrupted, not failed, and the
 * study runner waits and retries instead of carrying on.
 *
 * A failed ParallelPrompts task arrives as PromptTaskFailed, classified by
 * this same rule where the real exception still existed (ChildFailure::of()).
 */
final class TransientFailure
{
    public static function is(Throwable $e): bool
    {
        return $e instanceof FailoverableException
            || $e instanceof ConnectionException
            || $e instanceof BroadcastException
            || $e instanceof ProcessTimedOutException
            || ($e instanceof RequestException && $e->response->serverError())
            || ($e instanceof QueryException && str_contains($e->getMessage(), 'database is locked'))
            || ($e instanceof PromptTaskFailed && $e->failure->transient);
    }
}
